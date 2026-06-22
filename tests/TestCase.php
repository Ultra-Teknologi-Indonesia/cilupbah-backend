<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Drop Postgres custom types (e.g. the inventory_transfer_status ENUM) when the
     * test database is wiped. Without this, RefreshDatabase leaves orphaned types
     * behind and the next run fails on "type ... already exists".
     */
    protected bool $dropTypes = true;
}
