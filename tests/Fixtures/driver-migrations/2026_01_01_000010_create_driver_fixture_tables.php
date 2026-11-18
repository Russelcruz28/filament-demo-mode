<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shapes every sandbox driver must copy: a foreign key cycle (teams ⇄ people), a self-referencing key,
 * a composite primary key, a table without a key, JSON, and per-engine triggers, functions and procedures.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('captain_id')->nullable();
        });
        Schema::create('people', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('name_upper')->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('team_id')->nullable()->constrained('teams');
            $table->foreignId('manager_id')->nullable()->constrained('people');
        });
        Schema::table('teams', function (Blueprint $table): void {
            $table->foreign('captain_id')->references('id')->on('people');
        });
        Schema::create('memberships', function (Blueprint $table): void {
            $table->unsignedBigInteger('team_id');
            $table->unsignedBigInteger('person_id');
            $table->string('role');
            $table->primary(['team_id', 'person_id']);
            $table->foreign('team_id')->references('id')->on('teams');
            $table->foreign('person_id')->references('id')->on('people');
        });
        Schema::create('logs', function (Blueprint $table): void {
            $table->string('message');
            $table->integer('level');
        });

        match (DB::getDriverName()) {
            'pgsql' => $this->postgres(),
            'mysql', 'mariadb' => $this->mysql(),
            default => Schema::create('tickets', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('number')->default(0);
                $table->string('subject');
            }),
        };
    }

    private function postgres(): void
    {
        DB::statement('create sequence ticket_numbers start 1000');
        Schema::create('tickets', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('number')->default(DB::raw("nextval('ticket_numbers')"));
            $table->string('subject');
        });
        DB::unprepared(<<<'SQL'
            create function upper_name() returns trigger language plpgsql as $$
            begin new.name_upper := upper(new.name); return new; end $$;
            create trigger people_upper before insert on people for each row execute function upper_name();
            create function open_ticket(title text) returns bigint language plpgsql as $$
            declare result bigint;
            begin insert into tickets (subject) values (title) returning number into result; return result; end $$;
        SQL);
    }

    private function mysql(): void
    {
        Schema::create('tickets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('number')->default(0);
            $table->string('subject');
        });
        DB::unprepared('create trigger people_upper before insert on people for each row set new.name_upper = upper(new.name)');
        DB::unprepared('create procedure open_ticket(in title varchar(255)) begin
            insert into tickets (number, subject) select coalesce(max(number), 999) + 1, title from tickets; end');
    }

    public function down(): void {}
};
