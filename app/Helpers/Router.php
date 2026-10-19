<?php

namespace App\Helpers;

use Illuminate\Support\Str;
use Illuminate\Routing\Router as BaseRouter;

class Router extends BaseRouter
{
    public function __construct($events, $container = null)
    {
        parent::__construct($events, $container);
    }

    public function extendedResource($name, $controller, array $options = [])
    {
        $model = Str::singular($name);
        $this->put($name . '/{' . $model . '}/restore', $controller . '@restore')->name($name . '.restore');
        $this->delete($name . '/{' . $model . '}/permanently', $controller . '@destroyPermanently')->name($name . '.destroy.permanently');

        // Laravel's resource() always registers GET {name}/{id} ("show"). Controllers that have no show() page (units,
        // roles, users, contacts...) answered it with a 500 error; leave the route out so it is a plain 404.
        if (is_string($controller) && class_exists($controller) && ! method_exists($controller, 'show')) {
            $options['except'] = array_values(array_unique(array_merge((array) ($options['except'] ?? []), ['show'])));
        }

        return $this->resource($name, $controller, $options);
    }

    public function extendedResources(array $resources, array $options = [])
    {
        foreach ($resources as $name => $controller) {
            $this->extendedResource($name, $controller, $options);
        }
    }

    public function portResource($name, $controller, array $options = [])
    {
        $this->get($name . '/port/export', $controller . '@export')->name($name . '.export');
        $this->get($name . '/port/import', $controller . '@import')->name($name . '.import');
        $this->post($name . '/port/import', $controller . '@save')->name($name . '.import.save');
    }

    public function portResources(array $resources, array $options = [])
    {
        foreach ($resources as $name => $controller) {
            $this->portResource($name, $controller, $options);
        }
    }
}
