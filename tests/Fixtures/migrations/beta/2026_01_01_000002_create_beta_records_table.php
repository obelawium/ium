<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Obelaw\Ium\Bases\MigrationBase;

return new class extends MigrationBase
{
    protected ?string $module = 'beta';

    public function up(): void
    {
        Schema::create($this->prefix . 'records', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->prefix . 'records');
    }
};
