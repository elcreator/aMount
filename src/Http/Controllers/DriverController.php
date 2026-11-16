<?php

namespace Elcreator\aMount\Http\Controllers;

use Elcreator\aMount\Services\DriverInstaller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Installing a missing driver from the page; administrators only (see {@see DriverInstaller}). */
class DriverController extends Controller
{
    public function install(Request $request): JsonResponse
    {
        return $this->respond(fn () => (new DriverInstaller($this->actor()))->install($this->text($request, 'driver')));
    }

    public function status(int|string $id): JsonResponse
    {
        return $this->respond(fn () => ['task' => (new DriverInstaller($this->actor()))->status((int) $id)]);
    }
}
