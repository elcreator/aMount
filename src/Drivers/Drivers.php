<?php

namespace Elcreator\aMount\Drivers;

use InvalidArgumentException;

/**
 * Every driver aMount knows, installed or not.
 *
 * A driver whose package is missing is still listed — with the exact command to
 * install it — so a manager sees what is possible rather than a short list that
 * silently depends on what the last developer happened to require.
 *
 * Third parties add their own with {@see register()}, from a service provider:
 *
 *     Drivers::register(new MyDriver());
 */
final class Drivers
{
    /** @var array<string, Driver> */
    private static array $drivers = [];

    private static bool $booted = false;

    public static function register(Driver $driver): void
    {
        self::boot();
        self::$drivers[$driver->id()] = $driver;
    }

    /** @throws InvalidArgumentException */
    public static function get(string $id): Driver
    {
        self::boot();

        return self::$drivers[$id] ?? throw new InvalidArgumentException('Unknown mount driver "' . $id . '".');
    }

    public static function has(string $id): bool
    {
        self::boot();

        return isset(self::$drivers[$id]);
    }

    /** @return array<string, Driver> */
    public static function all(): array
    {
        self::boot();

        return self::$drivers;
    }

    /** Forget everything, including the built-ins' registration order. For tests. */
    public static function reset(): void
    {
        self::$drivers = [];
        self::$booted = false;
    }

    private static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        self::$booted = true;

        foreach ([
            new S3Driver(), new AsyncS3Driver(), new GoogleDriveDriver(), new DropboxDriver(), new OneDriveDriver(),
            new GcsDriver(), new AzureDriver(), new BunnyDriver(), new AliyunDriver(), new CosDriver(), new QiniuDriver(),
            new SftpDriver(), new FtpDriver(), new WebDavDriver(), new LocalDriver(), new ZipDriver(), new GridFsDriver(),
            new ComputerDriver(),
        ] as $driver) {
            self::$drivers[$driver->id()] = $driver;
        }
    }
}
