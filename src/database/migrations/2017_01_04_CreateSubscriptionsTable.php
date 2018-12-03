<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AlterUsersTable
{
    public function up()
    {
        Schema::create('subscriptions', function(Blueprint $table){
            $table->increments('id');
            $table->integer('user_id');
            $table->string('name');
            $table->integer('biller_id');
            $table->string('biller_plan')->nullable();
            $table->integer('biller_plan_id');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('payment_method');
            $table->string('status');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::drop('subscriptions');
    }
}