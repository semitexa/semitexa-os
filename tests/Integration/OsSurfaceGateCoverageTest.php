<?php

declare(strict_types=1);

namespace Semitexa\Os\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Application;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Os\Domain\Contract\OsSurfacePayloadInterface;

/**
 * Every window mounted under /os/app is gated as a console surface.
 *
 * {@see \Semitexa\Os\Pipeline\OsAdminGate} closes the gap between "somebody is
 * signed in" and "an operator of THIS console is signed in", and it finds the
 * routes to close it on by asking whether the payload implements
 * {@see OsSurfacePayloadInterface}. A namespace check could not do that job: the
 * sibling app packages — tasks, files, music, tictactoe, cms — each mount their
 * own windows under /os/app from their own namespaces.
 *
 * That design has one weakness, and it is the reason this test exists: a new
 * app package satisfies the gate by DOING NOTHING, because a payload without the
 * marker is simply not gated. The hole is invisible in the package that opens it
 * — its routes work — and visible only from here, where every discovered route
 * can be seen at once. MEASURED when this was written: eight routes across five
 * packages were reachable without an operator, including one added the same day.
 */
final class OsSurfaceGateCoverageTest extends TestCase
{
    /**
     * Routes under /os/app that are deliberately NOT console surfaces.
     *
     * An entry here is a decision, not a suppression: each one is public on
     * purpose and says why. Adding a line is how a reviewer is made to look.
     *
     * @var array<string, string>
     */
    private const PUBLIC_BY_DESIGN = [
        // An article's images are part of the published page, so a reader who
        // never signs in must be able to load them. The handler answers only
        // for the cms:content collection and refuses every other asset.
        '/os/app/cms/media/{assetId}' => 'article images are served to anonymous readers',
    ];

    /** @var list<array<string, mixed>> */
    private array $routes;

    protected function setUp(): void
    {
        // Booting the Application runs route discovery through the same path a
        // production worker does, which is the only way to see routes belonging
        // to packages this one does not depend on.
        new Application();

        /** @var AttributeDiscovery $discovery */
        $discovery = ContainerFactory::get()->get(AttributeDiscovery::class);
        $discovery->initialize();

        $this->routes = $discovery->getRoutes();
    }

    #[Test]
    public function every_os_app_route_is_a_console_surface(): void
    {
        $ungated = [];

        foreach ($this->routes as $route) {
            $path = (string) ($route['path'] ?? '');
            if (!str_starts_with($path, '/os/app')) {
                continue;
            }

            if (array_key_exists($path, self::PUBLIC_BY_DESIGN)) {
                continue;
            }

            $class = (string) ($route['class'] ?? '');
            if ($class === '' || !class_exists($class)) {
                continue; // a different test owns unreflectable routes
            }

            if (!is_subclass_of($class, OsSurfacePayloadInterface::class)) {
                $ungated[] = $path . '  [' . $class . ']';
            }
        }

        sort($ungated);

        self::assertSame(
            [],
            $ungated,
            "These /os/app routes are not gated as console surfaces. Implement "
            . OsSurfacePayloadInterface::class . " on the payload, or add the route to "
            . "PUBLIC_BY_DESIGN with the reason it is open:\n  - "
            . implode("\n  - ", $ungated),
        );
    }

    /**
     * The gate is a second lock, not the only one: a surface must also require
     * authentication in the first place, or an anonymous request never reaches
     * the listener that would turn it away.
     */
    #[Test]
    public function no_console_surface_is_declared_public(): void
    {
        $public = [];

        foreach ($this->routes as $route) {
            $class = (string) ($route['class'] ?? '');
            if ($class === '' || !class_exists($class) || !is_subclass_of($class, OsSurfacePayloadInterface::class)) {
                continue;
            }

            $access = $route['accessType'] ?? null;
            $name = $access instanceof \BackedEnum ? (string) $access->value : (string) $access;

            if ($name === 'public') {
                $public[] = (string) ($route['path'] ?? '?') . '  [' . $class . ']';
            }
        }

        sort($public);

        self::assertSame([], $public, "Console surfaces declared public:\n  - " . implode("\n  - ", $public));
    }

    /**
     * A floor under the two tests above: if discovery returns nothing, both pass
     * vacuously and the gate looks covered when it was never examined.
     */
    #[Test]
    public function the_console_surface_is_not_empty(): void
    {
        $surfaces = 0;
        foreach ($this->routes as $route) {
            $class = (string) ($route['class'] ?? '');
            if ($class !== '' && class_exists($class) && is_subclass_of($class, OsSurfacePayloadInterface::class)) {
                $surfaces++;
            }
        }

        self::assertGreaterThan(10, $surfaces, 'Route discovery found almost no console surfaces; the checks above would pass vacuously.');
    }
}
