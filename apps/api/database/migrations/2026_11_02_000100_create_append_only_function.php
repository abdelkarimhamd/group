<?php

use App\Support\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        AppendOnly::createFunction();
    }

    public function down(): void
    {
        AppendOnly::dropFunction();
    }
};
