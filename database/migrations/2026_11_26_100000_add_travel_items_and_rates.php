<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // the rate of a travel's ticket and visa: how many TZS one unit of the travel's currency is worth (1 for TZS)
        if (Schema::hasTable('travels') && ! Schema::hasColumn('travels', 'exchange_rate')) {
            Schema::table('travels', fn (Blueprint $t) => $t->decimal('exchange_rate', 15, 6)->default(1)->after('currency'));
        }
        // the other costs of a travel: accommodation, transport, hotels, food, leisure and any other expense. An item that names a contact (a ticket / visa agent,
        // for example one that rented the traveller a car) is owed to that agent and falls on the agent's balance; an item without one is the company's own expense.
        if (! Schema::hasTable('travel_items')) {
            Schema::create('travel_items', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('travel_id')->index();
                $t->string('category', 60);                                        // from the Travels settings list (soft-coded)
                $t->string('description', 255)->nullable();
                $t->unsignedBigInteger('contact_id')->nullable()->index();         // the agent it is owed to, if any
                $t->string('currency', 3)->default('TZS');
                $t->decimal('exchange_rate', 15, 6)->default(1);                   // TZS per one unit of the currency
                $t->decimal('amount', 15, 2);                                      // in the item's currency: this is what the agent is owed
                $t->decimal('amount_tzs', 15, 2);                                  // amount x rate, kept so reports add up in one currency
                $t->unsignedSmallInteger('sort_order')->default(0);
                $t->timestamps();
                $t->softDeletes();
                $t->index(['contact_id', 'currency'], 'travel_items_agent_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_items');
        if (Schema::hasColumn('travels', 'exchange_rate')) {
            Schema::table('travels', fn (Blueprint $t) => $t->dropColumn('exchange_rate'));
        }
    }
};
