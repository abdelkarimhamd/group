<?php

namespace App\Support;

enum ActorType: string
{
    case System = 'system';
    case Admin = 'admin';
    case User = 'user';
}
