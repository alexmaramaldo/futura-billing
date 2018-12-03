<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AlterUsersTable
{
    public function up()
    {
        Schema::table('users', function(Blueprint $table){
            if (!Schema::hasColumn('biller_id')) {
                $table->integer('biller_id')->nullable();
            }
            if (!Schema::hasColumn('cpf')) {
                $table->integer('cpf')->nullable();
            }
            if (!Schema::hasColumn('cartao')) {
                $table->string('cartao')->nullable();
            }
            if (!Schema::hasColumn('cartao_numero')) {
                $table->string('cartao_numero')->nullable();
            }
        });
    }
}