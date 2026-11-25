<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Obelaw\Ium\Bases\MigrationBase;

return new class extends MigrationBase
{
    protected ?string $module = 'alpha';

    public function up(): void
    {
        Schema::create($this->prefix . 'items', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->prefix . 'items');
    }
};
