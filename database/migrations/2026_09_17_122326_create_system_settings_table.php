<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();

            /*
            |--------------------------------------------------------------------------
            | General
            |--------------------------------------------------------------------------
            */

            $table->string('application_name')
                ->default('Mayor Social Analytics');

            $table->string('municipality_name')
                ->default('Adama City');

            $table->text('application_description')
                ->nullable();

            $table->string('support_email')
                ->nullable();

            $table->string('support_phone')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Facebook
            |--------------------------------------------------------------------------
            */

            $table->string('facebook_graph_version')
                ->default('v26.0');

            $table->boolean('facebook_sync_enabled')
                ->default(true);

            $table->boolean('facebook_auto_sync')
                ->default(true);

            $table->string('facebook_sync_interval')
                ->default('15');

            $table->boolean('facebook_posts_enabled')
                ->default(true);

            $table->boolean('facebook_comments_enabled')
                ->default(true);

            $table->boolean('facebook_reactions_enabled')
                ->default(true);

            $table->boolean('facebook_insights_enabled')
                ->default(true);

            /*
            |--------------------------------------------------------------------------
            | AI & Analytics
            |--------------------------------------------------------------------------
            */

            $table->boolean('ai_enabled')
                ->default(true);

            $table->string('ai_service_url')
                ->nullable();

            $table->unsignedInteger('ai_batch_size')
                ->default(32);

            $table->boolean('ai_automatic_analysis')
                ->default(true);

           

            /*
            |--------------------------------------------------------------------------
            | Notifications
            |--------------------------------------------------------------------------
            */

            $table->boolean('email_notifications')
                ->default(true);

            $table->boolean('failed_sync_alerts')
                ->default(true);

            $table->boolean('ai_failure_alerts')
                ->default(true);

            $table->boolean('security_alerts')
                ->default(true);

            $table->string('notification_email')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Security
            |--------------------------------------------------------------------------
            */

            $table->unsignedTinyInteger('password_min_length')
                ->default(8);

            $table->unsignedTinyInteger('max_login_attempts')
                ->default(5);

            $table->unsignedInteger('lockout_minutes')
                ->default(15);

            $table->boolean('require_strong_password')
                ->default(true);

            $table->boolean('require_two_factor')
                ->default(false);

            /*
            |--------------------------------------------------------------------------
            | Reports
            |--------------------------------------------------------------------------
            */

            $table->boolean('report_pdf_enabled')
                ->default(true);

            $table->boolean('report_excel_enabled')
                ->default(true);

            $table->string('report_default_format')
                ->default('pdf');

            $table->boolean('report_logo_enabled')
                ->default(true);

            $table->text('report_footer')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Maintenance
            |--------------------------------------------------------------------------
            */

            $table->boolean('maintenance_mode')
                ->default(false);

            $table->text('maintenance_message')
                ->nullable();

            $table->boolean('cache_enabled')
                ->default(true);

            $table->boolean('scheduled_tasks_enabled')
                ->default(true);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};