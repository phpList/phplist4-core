<?php
declare(strict_types=1);

namespace PhpList\PhpList4\Composer;

use Composer\Package\PackageInterface;
use Composer\Script\Event;
use PhpList\PhpList4\Core\ApplicationStructure;
use Sensio\Bundle\DistributionBundle\Composer\ScriptHandler as SensioScriptHandler;
use Symfony\Component\Filesystem\Filesystem;

/**
 * This class provides Composer-related functionality for setting up and managing phpList modules.
 *
 * @author Oliver Klee <oliver@phplist.com>
 */
class ScriptHandler extends SensioScriptHandler
{
    /**
     * @var string
     */
    const CORE_PACKAGE_NAME = 'phplist/phplist4-core';

    /**
     * @var string
     */
    const BUNDLE_CONFIGURATION_FILE = '/Configuration/bundles.yml';

    /**
     * @var string
     */
    const ROUTES_CONFIGURATION_FILE = '/Configuration/routing_modules.yml';

    /**
     * @var string
     */
    const PARAMETERS_CONFIGURATION_FILE = '/Configuration/parameters.yml';

    /**
     * @var string
     */
    const PARAMETERS_TEMPLATE_FILE = '/Configuration/parameters.yml.dist';

    /**
     * @return string absolute application root directory without the trailing slash
     *
     * @throws \RuntimeException if there is no composer.json in the application root
     */
    private static function getApplicationRoot(): string
    {
        $applicationStructure = new ApplicationStructure();
        return $applicationStructure->getApplicationRoot();
    }

    /**
     * @return string absolute directory without the trailing slash
     */
    private static function getCoreDirectory(): string
    {
        return self::getApplicationRoot() . '/vendor/' . self::CORE_PACKAGE_NAME;
    }

    /**
     * Creates the "bin/" directory and its contents, copying it from the phplist4-core package.
     *
     * This method must not be called for the phplist4-core package itself.
     *
     * @param Event $event
     *
     * @return void
     *
     * @throws \DomainException if this method is called for the phplist4-core package
     */
    public static function createBinaries(Event $event)
    {
        self::preventScriptFromCorePackage($event);
        self::mirrorDirectoryFromCore('bin');
    }

    /**
     * Creates the "web/" directory and its contents, copying it from the phplist4-core package.
     *
     * This method must not be called for the phplist4-core package itself.
     *
     * @param Event $event
     *
     * @return void
     *
     * @throws \DomainException if this method is called for the phplist4-core package
     */
    public static function createPublicWebDirectory(Event $event)
    {
        self::preventScriptFromCorePackage($event);
        self::mirrorDirectoryFromCore('web');
    }

    /**
     * @param Event $event
     *
     * @return void
     *
     * @throws \DomainException if this method is called for the phplist4-core package
     */
    private static function preventScriptFromCorePackage(Event $event)
    {
        $composer = $event->getComposer();
        $packageName = $composer->getPackage()->getName();
        if ($packageName === self::CORE_PACKAGE_NAME) {
            throw new \DomainException(
                'This Composer script must not be called for the phplist4-core package itself.',
                1501240572934
            );
        }
    }

    /**
     * Copies a directory from the core package.
     *
     * This method overwrites existing files, but will not delete any files.
     *
     * This method must not be called for the phplist4-core package itself.
     *
     * @param string $directoryWithoutSlashes directory name (without any slashes) relative to the core package
     *
     * @return void
     */
    private static function mirrorDirectoryFromCore(string $directoryWithoutSlashes)
    {
        $directoryWithSlashes = '/' . $directoryWithoutSlashes . '/';

        $fileSystem = new Filesystem();
        $fileSystem->mirror(
            self::getCoreDirectory() . $directoryWithSlashes,
            self::getApplicationRoot() . $directoryWithSlashes,
            null,
            ['override' => true, 'delete' => false]
        );
    }

    /**
     * Echos the names and version numbers of all installed phpList modules.
     *
     * @param Event $event
     *
     * @return void
     */
    public static function listModules(Event $event)
    {
        $packageRepository = new PackageRepository();
        $packageRepository->injectComposer($event->getComposer());

        $modules = $packageRepository->findModules();
        $maximumPackageNameLength = self::calculateMaximumPackageNameLength($modules);

        foreach ($modules as $module) {
            $paddedName = str_pad($module->getName(), $maximumPackageNameLength + 1);
            echo $paddedName . ' ' . $module->getPrettyVersion() . PHP_EOL;
        }
    }

    /**
     * @param PackageInterface[] $modules
     *
     * @return int
     */
    private static function calculateMaximumPackageNameLength(array $modules): int
    {
        $maximumLength = 0;
        foreach ($modules as $module) {
            $maximumLength = max($maximumLength, strlen($module->getName()));
        }

        return $maximumLength;
    }

    /**
     * Creates the configuration file for the Symfony bundles provided by the modules.
     *
     * @param Event $event
     *
     * @return void
     */
    public static function createBundleConfiguration(Event $event)
    {
        self::createAndWriteFile(
            self::getApplicationRoot() . self::BUNDLE_CONFIGURATION_FILE,
            self::createAndInitializeModuleFinder($event)->createBundleConfigurationYaml()
        );
    }

    /**
     * Writes $contents to the file with the path $path.
     *
     * If the file does not exist yet, it will be created.
     *
     * If the file already exists, it will be overwritten.
     *
     * @param string $path
     * @param string $contents
     *
     * @return void
     */
    private static function createAndWriteFile(string $path, string $contents)
    {
        $fileHandle = fopen($path, 'wb');
        fwrite($fileHandle, $contents);
        fclose($fileHandle);
    }

    /**
     * Creates the routes file for the Symfony bundles provided by the modules.
     *
     * @param Event $event
     *
     * @return void
     */
    public static function createRoutesConfiguration(Event $event)
    {
        self::createAndWriteFile(
            self::getApplicationRoot() . self::ROUTES_CONFIGURATION_FILE,
            self::createAndInitializeModuleFinder($event)->createRouteConfigurationYaml()
        );
    }

    /**
     * @param Event $event
     *
     * @return ModuleFinder
     */
    private static function createAndInitializeModuleFinder(Event $event): ModuleFinder
    {
        $packageRepository = new PackageRepository();
        $packageRepository->injectComposer($event->getComposer());

        $bundleFinder = new ModuleFinder();
        $bundleFinder->injectPackageRepository($packageRepository);

        return $bundleFinder;
    }

    /**
     * Clears the caches of all environments.
     *
     * @param Event $event
     *
     * @return void
     */
    public static function clearAllCaches(Event $event)
    {
        $environments = ['test', 'dev', 'prod'];
        $consoleDir = self::getConsoleDir($event, 'clear the cache');
        if ($consoleDir === null) {
            return;
        }

        foreach ($environments as $environment) {
            self::executeCommand($event, $consoleDir, 'cache:clear --no-warmup -e ' . $environment);
        }
    }

    /**
     * Warms the production cache.
     *
     * @param Event $event
     *
     * @return void
     */
    public static function warmProductionCache(Event $event)
    {
        $consoleDir = self::getConsoleDir($event, 'warm the cache');
        if ($consoleDir === null) {
            return;
        }

        self::executeCommand($event, $consoleDir, 'cache:warm -e prod');
    }

    /**
     * Creates the parameters.yml configuration file.
     *
     * @return void
     */
    public static function createParametersConfiguration()
    {
        $configurationFilePath = self::getApplicationRoot() . self::PARAMETERS_CONFIGURATION_FILE;
        if (file_exists($configurationFilePath)) {
            return;
        }

        $templateFilePath = __DIR__ . '/../..' . self::PARAMETERS_TEMPLATE_FILE;
        $template = file_get_contents($templateFilePath);

        $secret = bin2hex(random_bytes(20));
        $configuration = sprintf($template, $secret);

        self::createAndWriteFile($configurationFilePath, $configuration);
    }
}
