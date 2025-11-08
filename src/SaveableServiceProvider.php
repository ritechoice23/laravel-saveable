<?php

namespace Ritechoice23\Saveable;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class SaveableServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-saveable')
            ->hasConfigFile('saveable')
            ->hasMigration('2025_11_08_000001_create_collections_table')
            ->hasMigration('2025_11_08_000002_create_saves_table');
    }
}
