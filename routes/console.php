<?php

use App\Models\Provider;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;

/*
 * Primera instalación: crea los proveedores por defecto solo si no hay ninguno.
 * Se llama en cada arranque del contenedor de producción, así que no debe
 * volver a crear proveedores que el usuario haya borrado.
 */
Artisan::command('pricetrack:install', function () {
    if (Provider::exists()) {
        $this->info('Ya hay proveedores: no se siembra nada.');

        return;
    }

    $this->call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);
    $this->info('Proveedores por defecto creados.');
})->purpose('Crea los proveedores por defecto en una instalación nueva');
