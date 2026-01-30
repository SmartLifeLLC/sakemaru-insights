<?php

namespace App\Models;

use App\Models\Sakemaru\User as SakemaruUser;

/**
 * Session compatibility class for cross-application integration.
 *
 * Other applications store authenticated users with class name 'App\Models\User'.
 * This class enables session sharing between Insights, WMS, and Trade applications.
 */
class User extends SakemaruUser
{
    // Inherits all functionality from App\Models\Sakemaru\User
}
