<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracking_items', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->index();              // tag Jubelio / Epic / Omnichannel
            $table->string('method', 10)->nullable();        // GET/POST/... (null untuk epic/omnichannel)
            $table->string('endpoint');                      // path Jubelio atau judul task
            $table->text('function_id');                     // "untuk apa" (Bahasa Indonesia)
            $table->text('cilupbah_impl')->nullable();       // controller@method target
            $table->string('status', 12)->default('todo')->index();   // done|in_progress|todo|blocked
            $table->string('baseline_status', 12)->default('todo');   // status awal dari dokumen
            $table->string('pic')->nullable()->index();      // Darriel|Rasyid
            $table->text('notes')->nullable();               // catatan bebas (editable)
            $table->string('priority', 8)->nullable();       // P0..P3
            $table->string('source', 16)->default('jubelio');// jubelio|omnichannel|epic
            $table->string('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['method', 'endpoint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_items');
    }
};
