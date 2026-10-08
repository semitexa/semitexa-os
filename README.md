# Semitexa OS

`semitexa/os`

An intent-first shell for a Semitexa application: you say what you want, the assistant plans it over the registered skills (`#[AsAiSkill]` from `semitexa/llm`), runs it, and shows the result; apps open as dialog windows on demand. The planner runs on `semitexa/llm`, so an LLM backend must be configured.

## Install

Not included by the installer. Add it to an existing project from the project root:

```bash
docker compose run --rm --no-deps --user "$(id -u):$(id -g)" app composer require semitexa/os
bin/semitexa server:restart
bin/semitexa orm:sync
```

Composer also installs `semitexa/weave` and `semitexa/platform-user`. In web mode the shell requires a sign-in by default; create an account with `bin/semitexa user:add --email=… --role=owner`.

## What it provides

- The shell at `/os` and sign-in at `/os/login` (sign-out `/os/login/out`); the paths are configurable with `SEMITEXA_OS_SHELL_PATH`, `SEMITEXA_OS_LOGIN_PATH` and `SEMITEXA_OS_LOGOUT_PATH`.
- Built-in apps, each an assistant skill that opens a dialog: `Calendar`, `Notes`, `Terminal`, `Prompts` (browse and override the prompt catalog), `Settings`, `Updates`.
- Assistant skills for the environment itself, among them `design-skin` / `reset-skin`, `set-theme-mode`, `set-locale`, `rename-assistant`, `add-input-layout` / `remove-input-layout`, `remember` / `recall`, `attach-folder`, `calendar-create`, `os:status`, `os:updates`.
- A conversation history and a knowledge graph woven from it into `semitexa/weave` (a background timer; `OS_WEAVER=off` disables it).
- Tables `os_conversation_turn`, `os_conversation_summary`, `os_process`.
- Console commands: `os:design-skin`, `os:reset-skin`, `os:weave`.
- Settings: `SEMITEXA_WINDOW_MODE` (`web`, the default, or `os`), `SEMITEXA_OS_AUTH` (`1`/`0` forces sign-in on or off), `SEMITEXA_OS_LOCALE`.
- `resources/os-desktop/`: the optional native pieces for running the shell as a Linux desktop (window manager, local bridge, X session). See [its README](resources/os-desktop/README.md).

More apps ship as separate packages: `semitexa/files`, `semitexa/music`, `semitexa/tasks`, `semitexa/tictactoe`, `semitexa/webapps`, `semitexa/cms`.

## Documentation

Commands: https://semitexa.com/docs/reference/commands-os

## License

MIT, see [LICENSE](LICENSE).
