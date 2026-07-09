/*
 * semitexa-wm — a primitive but real X11 window manager for Semitexa OS.
 *
 * Stage 2: Semitexa-themed reparenting frames with minimize / maximize / close
 * buttons, move (titlebar or Alt+drag), resize (Alt+Button3), click-to-focus +
 * raise, Alt+Tab window cycling (restores minimized), Alt+Return terminal,
 * Alt+F4 close, and a special frameless full-screen "desktop" window
 * (WM_CLASS = SemitexaDesktop) that hosts the web shell / launcher.
 *
 * Deliberately minimal: no full ICCCM/EWMH. We control the app set.
 */
#include <X11/Xlib.h>
#include <X11/Xutil.h>
#include <X11/Xatom.h>
#include <X11/keysym.h>
#include <X11/Xft/Xft.h>
#include <X11/extensions/shape.h>
#include <X11/cursorfont.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
#include <signal.h>
#include <locale.h>

#define TITLE_H   28
#define BORDER    2
#define BTN_SZ    18
#define FRAME_RADIUS 10          /* rounded frame corners — matches .os-win border-radius (web parity) */
#define BTN_GAP   8
#define BTN_PAD   6
#define MIN_W     140
#define MIN_H     80
#define MAX_CLIENTS 128

/* Semitexa palette (0xRRGGBB) */
#define C_TITLE_INACT  0x0f1f33
#define C_TITLE_ACT    0x143a5e
#define C_BORDER_INACT 0x24405f
#define C_BORDER_ACT   0x37b7ff   /* cyan accent */
#define C_TEXT         0xd6e6ff
#define C_TEXT_DIM     0x6f8bb0
#define C_BTN          0x1c3350
#define C_BTN_GLYPH    0x9fc4ec
#define C_CLOSE        0xe0655f
#define C_CLOSE_GLYPH  0xffffff
#define C_ACCENT       0x37b7ff
#define C_DESKTOP_BG   0x0a1a2f

/* Edge-resize grips: invisible InputOnly strips over the frame's edges and
   corners (the client window covers everything below the titlebar, so plain
   border clicks never reach the frame). Mirrors the web shell's .os-win__rs
   handles: 6px strips, 14px corners, directional cursors. */
#define GRIP_EDGE   6
#define GRIP_CORNER 14
#define GRIP_N 1
#define GRIP_S 2
#define GRIP_E 4
#define GRIP_W 8
#define NGRIPS 8

typedef struct {
    Window client;
    Window frame;
    Window grip[NGRIPS];
    int x, y;            /* frame position */
    int cw, ch;          /* client (content) size */
    int minimized;
    int maximized;
    int sx, sy, scw, sch; /* saved geom for un-maximize */
    int used;
} Client;

static const int grip_dir[NGRIPS] = {
    GRIP_N, GRIP_S, GRIP_E, GRIP_W,
    GRIP_N | GRIP_E, GRIP_N | GRIP_W, GRIP_S | GRIP_E, GRIP_S | GRIP_W,
};
static const unsigned int grip_cursor_shape[NGRIPS] = {
    XC_top_side, XC_bottom_side, XC_right_side, XC_left_side,
    XC_top_right_corner, XC_top_left_corner,
    XC_bottom_right_corner, XC_bottom_left_corner,
};
static Cursor grip_cursor[NGRIPS];

static Display *dpy;
static Window   root;
static int      screen;
static GC       gc;
static XftFont *font;
static Atom     WM_PROTOCOLS, WM_DELETE_WINDOW;
static Client   clients[MAX_CLIENTS];
static Window   focused = None;
static Window   desktop_win = None;   /* frameless full-screen shell/launcher */

static struct {
    int mode;            /* 0 none, 1 move, 2 resize (Alt+B3), 3 edge resize */
    int edge;            /* GRIP_* mask for mode 3 */
    Client *c;
    int start_px, start_py;
    int start_x, start_y, start_w, start_h;
} drag;

static void screen_size(int *w, int *h) {
    XWindowAttributes wa;
    if (XGetWindowAttributes(dpy, root, &wa)) { *w = wa.width; *h = wa.height; }
    else { *w = DisplayWidth(dpy, screen); *h = DisplayHeight(dpy, screen); }
}

static unsigned long pixel(int rgb) {
    XColor col;
    col.red   = ((rgb >> 16) & 0xff) * 257;
    col.green = ((rgb >> 8)  & 0xff) * 257;
    col.blue  = ( rgb        & 0xff) * 257;
    col.flags = DoRed | DoGreen | DoBlue;
    XAllocColor(dpy, DefaultColormap(dpy, screen), &col);
    return col.pixel;
}
static void xft_color(int rgb, XftColor *out) {
    XRenderColor rc;
    rc.red = ((rgb >> 16) & 0xff) * 257;
    rc.green = ((rgb >> 8) & 0xff) * 257;
    rc.blue = (rgb & 0xff) * 257;
    rc.alpha = 0xffff;
    XftColorAllocValue(dpy, DefaultVisual(dpy, screen),
                       DefaultColormap(dpy, screen), &rc, out);
}

static Client *find_by_frame(Window w) {
    for (int i = 0; i < MAX_CLIENTS; i++)
        if (clients[i].used && clients[i].frame == w) return &clients[i];
    return NULL;
}
static Client *find_by_client(Window w) {
    for (int i = 0; i < MAX_CLIENTS; i++)
        if (clients[i].used && clients[i].client == w) return &clients[i];
    return NULL;
}
static Client *alloc_client(void) {
    for (int i = 0; i < MAX_CLIENTS; i++)
        if (!clients[i].used) { memset(&clients[i], 0, sizeof(Client)); clients[i].used = 1; return &clients[i]; }
    return NULL;
}
static Client *find_by_grip(Window w, int *dir) {
    for (int i = 0; i < MAX_CLIENTS; i++) {
        if (!clients[i].used) continue;
        for (int g = 0; g < NGRIPS; g++)
            if (clients[i].grip[g] == w) { *dir = grip_dir[g]; return &clients[i]; }
    }
    return NULL;
}

/* Keep the grips glued to the frame's current edges; hide them while
   maximized (parity with .os-win.max hiding .os-win__rs). */
static void place_grips(Client *c) {
    int fw = c->cw, fh = c->ch + TITLE_H;
    for (int g = 0; g < NGRIPS; g++) {
        if (!c->grip[g]) continue;
        if (c->maximized) { XUnmapWindow(dpy, c->grip[g]); continue; }
        int x = 0, y = 0, w = GRIP_CORNER, h = GRIP_CORNER;
        switch (grip_dir[g]) {
            case GRIP_N: x = GRIP_CORNER; y = 0;              w = fw - 2 * GRIP_CORNER; h = 4; break;
            case GRIP_S: x = GRIP_CORNER; y = fh - GRIP_EDGE; w = fw - 2 * GRIP_CORNER; h = GRIP_EDGE; break;
            case GRIP_E: x = fw - GRIP_EDGE; y = GRIP_CORNER; w = GRIP_EDGE; h = fh - 2 * GRIP_CORNER; break;
            case GRIP_W: x = 0;              y = GRIP_CORNER; w = GRIP_EDGE; h = fh - 2 * GRIP_CORNER; break;
            case GRIP_N | GRIP_E: x = fw - GRIP_CORNER; y = 0; break;
            case GRIP_N | GRIP_W: x = 0;                y = 0; break;
            case GRIP_S | GRIP_E: x = fw - GRIP_CORNER; y = fh - GRIP_CORNER; break;
            case GRIP_S | GRIP_W: x = 0;                y = fh - GRIP_CORNER; break;
        }
        if (w < 1) w = 1;
        if (h < 1) h = 1;
        XMoveResizeWindow(dpy, c->grip[g], x, y, w, h);
        XMapWindow(dpy, c->grip[g]);
        XRaiseWindow(dpy, c->grip[g]);   /* above the client, or it never sees clicks */
    }
}

static void create_grips(Client *c) {
    XSetWindowAttributes a;
    for (int g = 0; g < NGRIPS; g++) {
        a.event_mask = ButtonPressMask;
        a.cursor = grip_cursor[g];
        c->grip[g] = XCreateWindow(dpy, c->frame, 0, 0, 1, 1, 0, 0, InputOnly,
                                   CopyFromParent, CWEventMask | CWCursor, &a);
    }
    place_grips(c);
}

static void get_title(Window w, char *buf, int n) {
    XTextProperty tp;
    buf[0] = 0;
    if (XGetWMName(dpy, w, &tp) && tp.value && tp.nitems) {
        char **list = NULL; int cnt = 0;
        if (XmbTextPropertyToTextList(dpy, &tp, &list, &cnt) >= Success && cnt && list) {
            strncpy(buf, list[0], n - 1); buf[n - 1] = 0; XFreeStringList(list);
        } else { strncpy(buf, (char *)tp.value, n - 1); buf[n - 1] = 0; }
        XFree(tp.value);
    }
    if (!buf[0]) strncpy(buf, "window", n - 1);
}

/* button x-origin for slot (0=close, 1=maximize, 2=minimize), right-aligned */
static int btn_x(int fw, int slot) {
    return fw - BTN_PAD - (slot + 1) * BTN_SZ - slot * BTN_GAP;
}

/* Round the frame's outer corners via the SHAPE extension so native OS-mode
   windows match the web .os-win border-radius. Maximized windows stay square
   (like .os-win.max). Called from draw_frame(), so the mask always tracks size. */
static void shape_frame(Client *c) {
    int w = c->cw, h = c->ch + TITLE_H;
    int r = c->maximized ? 0 : FRAME_RADIUS;
    if (w <= 0 || h <= 0) return;
    Pixmap mask = XCreatePixmap(dpy, c->frame, w, h, 1);
    GC mg = XCreateGC(dpy, mask, 0, NULL);
    XSetForeground(dpy, mg, 0);
    XFillRectangle(dpy, mask, mg, 0, 0, w, h);
    XSetForeground(dpy, mg, 1);
    if (r > 0 && w > 2 * r && h > 2 * r) {
        XFillRectangle(dpy, mask, mg, r, 0, w - 2 * r, h);
        XFillRectangle(dpy, mask, mg, 0, r, w, h - 2 * r);
        XFillArc(dpy, mask, mg, 0,         0,         2 * r, 2 * r, 0, 360 * 64);
        XFillArc(dpy, mask, mg, w - 2 * r, 0,         2 * r, 2 * r, 0, 360 * 64);
        XFillArc(dpy, mask, mg, 0,         h - 2 * r, 2 * r, 2 * r, 0, 360 * 64);
        XFillArc(dpy, mask, mg, w - 2 * r, h - 2 * r, 2 * r, 2 * r, 0, 360 * 64);
    } else {
        XFillRectangle(dpy, mask, mg, 0, 0, w, h);
    }
    XShapeCombineMask(dpy, c->frame, ShapeBounding, 0, 0, mask, ShapeSet);
    XFreeGC(dpy, mg);
    XFreePixmap(dpy, mask);
}

static void draw_frame(Client *c) {
    int active = (c->client == focused);
    int fw = c->cw;
    int by = (TITLE_H - BTN_SZ) / 2;

    XSetForeground(dpy, gc, pixel(active ? C_TITLE_ACT : C_TITLE_INACT));
    XFillRectangle(dpy, c->frame, gc, 0, 0, fw, TITLE_H);
    XSetForeground(dpy, gc, pixel(active ? C_ACCENT : C_BORDER_INACT));
    XFillRectangle(dpy, c->frame, gc, 0, TITLE_H - 2, fw, 2);

    /* close (slot 0, red) */
    int cx = btn_x(fw, 0);
    XSetForeground(dpy, gc, pixel(C_CLOSE));
    XFillRectangle(dpy, c->frame, gc, cx, by, BTN_SZ, BTN_SZ);
    XSetForeground(dpy, gc, pixel(C_CLOSE_GLYPH));
    { int m = 5;
      XDrawLine(dpy, c->frame, gc, cx+m, by+m, cx+BTN_SZ-m, by+BTN_SZ-m);
      XDrawLine(dpy, c->frame, gc, cx+BTN_SZ-m, by+m, cx+m, by+BTN_SZ-m); }

    /* maximize (slot 1) */
    int mx = btn_x(fw, 1);
    XSetForeground(dpy, gc, pixel(C_BTN));
    XFillRectangle(dpy, c->frame, gc, mx, by, BTN_SZ, BTN_SZ);
    XSetForeground(dpy, gc, pixel(C_BTN_GLYPH));
    XDrawRectangle(dpy, c->frame, gc, mx+4, by+4, BTN_SZ-9, BTN_SZ-9);

    /* minimize (slot 2) */
    int nx = btn_x(fw, 2);
    XSetForeground(dpy, gc, pixel(C_BTN));
    XFillRectangle(dpy, c->frame, gc, nx, by, BTN_SZ, BTN_SZ);
    XSetForeground(dpy, gc, pixel(C_BTN_GLYPH));
    XDrawLine(dpy, c->frame, gc, nx+4, by+BTN_SZ-6, nx+BTN_SZ-4, by+BTN_SZ-6);

    /* title text, clipped before the buttons */
    char title[256];
    get_title(c->client, title, sizeof(title));
    XftColor tc; xft_color(active ? C_TEXT : C_TEXT_DIM, &tc);
    XftDraw *d = XftDrawCreate(dpy, c->frame, DefaultVisual(dpy, screen),
                              DefaultColormap(dpy, screen));
    int ty = (TITLE_H - (font->ascent + font->descent)) / 2 + font->ascent;
    Region r = XCreateRegion();
    XRectangle rr = { 0, 0, (unsigned short)(nx - 10 > 0 ? nx - 10 : 0), TITLE_H };
    XUnionRectWithRegion(&rr, r, r);
    XftDrawSetClip(d, r);
    XftDrawStringUtf8(d, &tc, font, 12, ty, (FcChar8 *)title, strlen(title));
    XDestroyRegion(r);
    XftDrawDestroy(d);
    XftColorFree(dpy, DefaultVisual(dpy, screen), DefaultColormap(dpy, screen), &tc);

    shape_frame(c);   /* keep the rounded-corner mask in sync with the current size */
    place_grips(c);   /* grips track every size change (drag, configure, maximize) */
}

static void set_border(Client *c) {
    XSetWindowBorder(dpy, c->frame,
        pixel(c->client == focused ? C_BORDER_ACT : C_BORDER_INACT));
}

static void focus_client(Client *c) {
    if (!c) return;
    if (c->minimized) {         /* un-minimize on focus */
        XMapWindow(dpy, c->frame);
        c->minimized = 0;
    }
    Window prev = focused;
    focused = c->client;
    XSetInputFocus(dpy, c->client, RevertToPointerRoot, CurrentTime);
    XRaiseWindow(dpy, c->frame);
    Client *pc = find_by_client(prev);
    if (pc && pc != c) { set_border(pc); draw_frame(pc); }
    set_border(c); draw_frame(c);
}

/* The desktop shell is identified by its window TITLE ("SemitexaDesktop"),
 * NOT WM_CLASS — every window of one chromium instance shares the class, so
 * class would wrongly match app windows too. Title is per-window. */
static int is_desktop(Window w) {
    char t[256]; get_title(w, t, sizeof(t));
    return strcmp(t, "SemitexaDesktop") == 0;
}

static void focus_desktop(void) {
    if (desktop_win != None)
        XSetInputFocus(dpy, desktop_win, RevertToPointerRoot, CurrentTime);
}

static void make_desktop(Window w) {
    int sw, sh; screen_size(&sw, &sh);
    /* if the window was managed before its title arrived, our click-to-focus
     * sync grab is still on it — it would freeze the pointer on every click */
    XUngrabButton(dpy, AnyButton, AnyModifier, w);
    XSetWindowBorderWidth(dpy, w, 0);
    XMoveResizeWindow(dpy, w, 0, 0, sw, sh);
    XSelectInput(dpy, w, StructureNotifyMask | PropertyChangeMask);
    XMapWindow(dpy, w);
    XLowerWindow(dpy, w);
    desktop_win = w;
    if (focused == None) focus_desktop();  /* keyboard for the omnibar */
}

static void manage(Window w) {
    XWindowAttributes wa;
    if (!XGetWindowAttributes(dpy, w, &wa)) return;
    if (wa.override_redirect) return;
    if (find_by_client(w)) return;

    /* the desktop/launcher shell: frameless, full-screen, kept at the bottom */
    if (is_desktop(w)) { make_desktop(w); return; }

    Client *c = alloc_client();
    if (!c) return;

    int cw = wa.width  < MIN_W ? MIN_W : wa.width;
    int ch = wa.height < MIN_H ? MIN_H : wa.height;
    int fx = wa.x, fy = wa.y;
    if (fx < 0) fx = 60;
    if (fy < TITLE_H) fy = 50;

    Window frame = XCreateSimpleWindow(dpy, root, fx, fy, cw, ch + TITLE_H,
                                       BORDER, pixel(C_BORDER_INACT), pixel(C_TITLE_INACT));
    XSelectInput(dpy, frame,
        ButtonPressMask | ButtonReleaseMask | Button1MotionMask |
        ExposureMask | SubstructureRedirectMask | SubstructureNotifyMask);

    XAddToSaveSet(dpy, w);
    XReparentWindow(dpy, w, frame, 0, TITLE_H);
    XResizeWindow(dpy, w, cw, ch);
    XMapWindow(dpy, frame);
    XMapWindow(dpy, w);
    XSelectInput(dpy, w, PropertyChangeMask);

    XGrabButton(dpy, Button1, Mod1Mask, w, False,
        ButtonPressMask | ButtonReleaseMask | ButtonMotionMask,
        GrabModeAsync, GrabModeAsync, None, None);
    XGrabButton(dpy, Button3, Mod1Mask, w, False,
        ButtonPressMask | ButtonReleaseMask | ButtonMotionMask,
        GrabModeAsync, GrabModeAsync, None, None);
    XGrabButton(dpy, Button1, 0, w, False, ButtonPressMask,
        GrabModeSync, GrabModeAsync, None, None);

    c->client = w; c->frame = frame;
    c->x = fx; c->y = fy; c->cw = cw; c->ch = ch;
    create_grips(c);
    focus_client(c);
}

static void unmanage(Client *c, int destroyed) {
    if (!c) return;
    if (!destroyed) {
        XReparentWindow(dpy, c->client, root, c->x, c->y);
        XRemoveFromSaveSet(dpy, c->client);
    }
    XDestroyWindow(dpy, c->frame);
    if (focused == c->client) focused = None;
    c->used = 0;
    if (focused == None) {
        for (int i = 0; i < MAX_CLIENTS; i++)
            if (clients[i].used && !clients[i].minimized) { focus_client(&clients[i]); break; }
    }
    if (focused == None) focus_desktop();
}

static void close_client(Client *c) {
    Atom *protos = NULL; int n = 0, supports = 0;
    if (XGetWMProtocols(dpy, c->client, &protos, &n)) {
        for (int i = 0; i < n; i++) if (protos[i] == WM_DELETE_WINDOW) supports = 1;
        if (protos) XFree(protos);
    }
    if (supports) {
        XEvent e; memset(&e, 0, sizeof(e));
        e.xclient.type = ClientMessage; e.xclient.window = c->client;
        e.xclient.message_type = WM_PROTOCOLS; e.xclient.format = 32;
        e.xclient.data.l[0] = WM_DELETE_WINDOW; e.xclient.data.l[1] = CurrentTime;
        XSendEvent(dpy, c->client, False, NoEventMask, &e);
    } else XKillClient(dpy, c->client);
}

static void minimize(Client *c) {
    XUnmapWindow(dpy, c->frame);
    c->minimized = 1;
    if (focused == c->client) focused = None;
    for (int i = 0; i < MAX_CLIENTS; i++)
        if (clients[i].used && !clients[i].minimized) { focus_client(&clients[i]); break; }
    if (focused == None) focus_desktop();
}

static void toggle_maximize(Client *c) {
    if (!c->maximized) {
        c->sx = c->x; c->sy = c->y; c->scw = c->cw; c->sch = c->ch;
        int sw, sh; screen_size(&sw, &sh);
        c->x = 0; c->y = 0; c->cw = sw - 2 * BORDER; c->ch = sh - TITLE_H - 2 * BORDER;
        c->maximized = 1;
    } else {
        c->x = c->sx; c->y = c->sy; c->cw = c->scw; c->ch = c->sch;
        c->maximized = 0;
    }
    XMoveResizeWindow(dpy, c->frame, c->x, c->y, c->cw, c->ch + TITLE_H);
    XResizeWindow(dpy, c->client, c->cw, c->ch);
    draw_frame(c);
}

static void spawn(const char *cmd) {
    if (fork() == 0) {
        if (dpy) close(ConnectionNumber(dpy));
        setsid();
        execl("/bin/sh", "sh", "-c", cmd, (char *)NULL);
        exit(0);
    }
}

static void alt_tab(void) {
    /* cycle to the next managed window after the focused one, restoring it */
    int start = -1;
    for (int i = 0; i < MAX_CLIENTS; i++)
        if (clients[i].used && clients[i].client == focused) { start = i; break; }
    for (int off = 1; off <= MAX_CLIENTS; off++) {
        int i = (start + off + MAX_CLIENTS) % MAX_CLIENTS;
        if (clients[i].used) { focus_client(&clients[i]); return; }
    }
}

/* ---- events ---- */
static void on_maprequest(XMapRequestEvent *e) { manage(e->window); }

static void on_configurerequest(XConfigureRequestEvent *e) {
    Client *c = find_by_client(e->window);
    XWindowChanges wc;
    wc.x = e->x; wc.y = e->y; wc.width = e->width; wc.height = e->height;
    wc.border_width = e->border_width; wc.sibling = e->above; wc.stack_mode = e->detail;
    if (!c) { XConfigureWindow(dpy, e->window, e->value_mask, &wc); return; }
    if (c->maximized) return; /* ignore size requests while maximized */
    if (e->value_mask & CWWidth)  c->cw = e->width  < MIN_W ? MIN_W : e->width;
    if (e->value_mask & CWHeight) c->ch = e->height < MIN_H ? MIN_H : e->height;
    XResizeWindow(dpy, c->frame, c->cw, c->ch + TITLE_H);
    XResizeWindow(dpy, c->client, c->cw, c->ch);
    draw_frame(c);
}

static void on_buttonpress(XButtonEvent *e) {
    int dir = 0;
    Client *c = find_by_grip(e->window, &dir);
    if (c) {
        focus_client(c);
        drag.mode = 3; drag.edge = dir; drag.c = c;
        drag.start_px = e->x_root; drag.start_py = e->y_root;
        drag.start_x = c->x; drag.start_y = c->y;
        drag.start_w = c->cw; drag.start_h = c->ch;
        /* explicit grab: motion keeps flowing to us with the edge cursor even
           when the pointer crosses into the client (chromium) mid-drag */
        int g = 0; for (int i = 0; i < NGRIPS; i++) if (grip_dir[i] == dir) g = i;
        XGrabPointer(dpy, e->window, False,
                     PointerMotionMask | ButtonReleaseMask,
                     GrabModeAsync, GrabModeAsync, None, grip_cursor[g], CurrentTime);
        return;
    }
    c = find_by_frame(e->window);
    if (c) {
        focus_client(c);
        int fw = c->cw, by = (TITLE_H - BTN_SZ) / 2;
        int in_y = (e->y >= by && e->y <= by + BTN_SZ);
        int cx = btn_x(fw, 0), mx = btn_x(fw, 1), nx = btn_x(fw, 2);
        if (in_y && e->x >= cx && e->x <= cx + BTN_SZ) { close_client(c); return; }
        if (in_y && e->x >= mx && e->x <= mx + BTN_SZ) { toggle_maximize(c); return; }
        if (in_y && e->x >= nx && e->x <= nx + BTN_SZ) { minimize(c); return; }
        drag.mode = 1; drag.c = c;
        drag.start_px = e->x_root; drag.start_py = e->y_root;
        drag.start_x = c->x; drag.start_y = c->y;
        return;
    }
    c = find_by_client(e->window);
    if (!c) { XAllowEvents(dpy, ReplayPointer, CurrentTime); return; }
    focus_client(c);
    if (e->state & Mod1Mask) {
        drag.c = c;
        drag.start_px = e->x_root; drag.start_py = e->y_root;
        drag.start_x = c->x; drag.start_y = c->y;
        drag.start_w = c->cw; drag.start_h = c->ch;
        drag.mode = (e->button == Button3) ? 2 : 1;
    } else {
        XAllowEvents(dpy, ReplayPointer, CurrentTime);
    }
}

static void on_motion(XMotionEvent *e) {
    if (!drag.mode || !drag.c) return;
    while (XCheckTypedEvent(dpy, MotionNotify, (XEvent *)e)) {}
    int dx = e->x_root - drag.start_px, dy = e->y_root - drag.start_py;
    Client *c = drag.c;
    if (drag.mode == 1) {
        c->x = drag.start_x + dx; c->y = drag.start_y + dy;
        c->maximized = 0;
        XMoveWindow(dpy, c->frame, c->x, c->y);
    } else if (drag.mode == 3) {
        /* edge/corner resize: opposite edge stays anchored */
        int nx = drag.start_x, ny = drag.start_y;
        int nw = drag.start_w, nh = drag.start_h;
        if (drag.edge & GRIP_E) nw = drag.start_w + dx;
        if (drag.edge & GRIP_S) nh = drag.start_h + dy;
        if (drag.edge & GRIP_W) { nw = drag.start_w - dx; nx = drag.start_x + dx; }
        if (drag.edge & GRIP_N) { nh = drag.start_h - dy; ny = drag.start_y + dy; }
        if (nw < MIN_W) { if (drag.edge & GRIP_W) nx -= MIN_W - nw; nw = MIN_W; }
        if (nh < MIN_H) { if (drag.edge & GRIP_N) ny -= MIN_H - nh; nh = MIN_H; }
        c->x = nx; c->y = ny; c->cw = nw; c->ch = nh; c->maximized = 0;
        XMoveResizeWindow(dpy, c->frame, c->x, c->y, c->cw, c->ch + TITLE_H);
        XResizeWindow(dpy, c->client, c->cw, c->ch);
        draw_frame(c);
    } else {
        int nw = drag.start_w + dx, nh = drag.start_h + dy;
        if (nw < MIN_W) nw = MIN_W;
        if (nh < MIN_H) nh = MIN_H;
        c->cw = nw; c->ch = nh; c->maximized = 0;
        XResizeWindow(dpy, c->frame, c->cw, c->ch + TITLE_H);
        XResizeWindow(dpy, c->client, c->cw, c->ch);
        draw_frame(c);
    }
}

static void on_buttonrelease(XButtonEvent *e) {
    (void)e;
    if (drag.mode == 3) XUngrabPointer(dpy, CurrentTime);
    drag.mode = 0; drag.c = NULL;
}

static void on_expose(XExposeEvent *e) {
    if (e->count) return;
    Client *c = find_by_frame(e->window);
    if (c) draw_frame(c);
}
static void on_unmap(XUnmapEvent *e) {
    Client *c = find_by_client(e->window);
    if (c && !c->minimized) unmanage(c, 0);
}
static void on_destroy(XDestroyWindowEvent *e) {
    if (e->window == desktop_win) { desktop_win = None; return; }
    Client *c = find_by_client(e->window);
    if (c) unmanage(c, 1);
}
static void on_property(XPropertyEvent *e) {
    if (e->atom == XA_WM_NAME || e->atom == XInternAtom(dpy, "_NET_WM_NAME", False)) {
        /* title arrived/changed — the launcher may only now be identifiable */
        if (e->window != desktop_win && is_desktop(e->window)) {
            Client *c = find_by_client(e->window);
            if (c) {                       /* was framed: unframe and convert */
                XReparentWindow(dpy, c->client, root, 0, 0);
                XRemoveFromSaveSet(dpy, c->client);
                XDestroyWindow(dpy, c->frame);
                if (focused == c->client) focused = None;
                c->used = 0;
            }
            make_desktop(e->window);
            return;
        }
        Client *c = find_by_client(e->window);
        if (c) draw_frame(c);
    }
}
static void on_configurenotify(XConfigureEvent *e) {
    /* keep the desktop shell full-screen if the display is resized */
    if (e->window == root && desktop_win != None) {
        int sw, sh; screen_size(&sw, &sh);
        XMoveResizeWindow(dpy, desktop_win, 0, 0, sw, sh);
        XLowerWindow(dpy, desktop_win);
    }
}
static void on_key(XKeyEvent *e) {
    KeySym ks = XLookupKeysym(e, 0);
    if ((e->state & Mod1Mask) && ks == XK_Return)
        spawn("xterm -fn 9x15 -bg '#0d1f33' -fg '#cfe3ff' -title 'Semitexa Shell'");
    else if ((e->state & Mod1Mask) && ks == XK_F4) {
        Client *c = find_by_client(focused);
        if (c) close_client(c);
    } else if ((e->state & Mod1Mask) && (ks == XK_Tab)) {
        alt_tab();
    }
}

static int wm_detected = 0;
static int xerror_startup(Display *d, XErrorEvent *e) {
    (void)d; if (e->error_code == BadAccess) wm_detected = 1; return 0;
}
static int xerror(Display *d, XErrorEvent *e) { (void)d; (void)e; return 0; }

static void scan_existing(void) {
    Window r, parent, *ch = NULL; unsigned int n = 0;
    if (XQueryTree(dpy, root, &r, &parent, &ch, &n)) {
        for (unsigned int i = 0; i < n; i++) {
            XWindowAttributes wa;
            if (XGetWindowAttributes(dpy, ch[i], &wa) &&
                !wa.override_redirect && wa.map_state == IsViewable)
                manage(ch[i]);
        }
        if (ch) XFree(ch);
    }
}

int main(void) {
    signal(SIGCHLD, SIG_IGN);
    setlocale(LC_ALL, "");
    dpy = XOpenDisplay(NULL);
    if (!dpy) { fprintf(stderr, "semitexa-wm: cannot open display\n"); return 1; }
    screen = DefaultScreen(dpy);
    root = RootWindow(dpy, screen);

    XSetErrorHandler(xerror_startup);
    XSelectInput(dpy, root, SubstructureRedirectMask | SubstructureNotifyMask |
                            StructureNotifyMask | ButtonPressMask | KeyPressMask);
    XSync(dpy, False);
    if (wm_detected) { fprintf(stderr, "semitexa-wm: another WM is running\n"); return 1; }
    XSetErrorHandler(xerror);

    gc = XCreateGC(dpy, root, 0, NULL);
    font = XftFontOpenName(dpy, screen, "IBM Plex Sans:size=10");
    if (!font) font = XftFontOpenName(dpy, screen, "Open Sans:size=10");
    if (!font) font = XftFontOpenName(dpy, screen, "fixed");

    WM_PROTOCOLS     = XInternAtom(dpy, "WM_PROTOCOLS", False);
    WM_DELETE_WINDOW = XInternAtom(dpy, "WM_DELETE_WINDOW", False);

    for (int g = 0; g < NGRIPS; g++)
        grip_cursor[g] = XCreateFontCursor(dpy, grip_cursor_shape[g]);

    XSetWindowBackground(dpy, root, pixel(C_DESKTOP_BG));
    XClearWindow(dpy, root);

    XGrabKey(dpy, XKeysymToKeycode(dpy, XK_Return), Mod1Mask, root, True, GrabModeAsync, GrabModeAsync);
    XGrabKey(dpy, XKeysymToKeycode(dpy, XK_F4),     Mod1Mask, root, True, GrabModeAsync, GrabModeAsync);
    XGrabKey(dpy, XKeysymToKeycode(dpy, XK_Tab),    Mod1Mask, root, True, GrabModeAsync, GrabModeAsync);

    scan_existing();

    XEvent ev;
    for (;;) {
        XNextEvent(dpy, &ev);
        switch (ev.type) {
            case MapRequest:       on_maprequest(&ev.xmaprequest); break;
            case ConfigureRequest: on_configurerequest(&ev.xconfigurerequest); break;
            case ConfigureNotify:  on_configurenotify(&ev.xconfigure); break;
            case ButtonPress:      on_buttonpress(&ev.xbutton); break;
            case ButtonRelease:    on_buttonrelease(&ev.xbutton); break;
            case MotionNotify:     on_motion(&ev.xmotion); break;
            case Expose:           on_expose(&ev.xexpose); break;
            case UnmapNotify:      on_unmap(&ev.xunmap); break;
            case DestroyNotify:    on_destroy(&ev.xdestroywindow); break;
            case PropertyNotify:   on_property(&ev.xproperty); break;
            case KeyPress:         on_key(&ev.xkey); break;
        }
    }
    return 0;
}
