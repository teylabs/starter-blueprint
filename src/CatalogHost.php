<?php

namespace Tey\StarterBlueprint;

/**
 * Optional companion to LifecycleHost: a host that owns its feature catalog.
 *
 * `starter:maintain` builds its blueprints from this catalog when the bound host implements it,
 * and from the package catalog otherwise. A host command can also build blueprints itself with
 * `Blueprint::features($ids, $catalog)` and pass them to `Lifecycle::plan`, `apply` and `resume`.
 */
interface CatalogHost
{
    public function catalog(): Catalog;
}
