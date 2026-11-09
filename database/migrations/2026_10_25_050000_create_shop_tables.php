<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $this->createGuestTables();
        $this->createCatalogTables();
        $this->createOrderTables();
        $this->createDeliveryTables();
        $this->createSubscriptionTables();
        $this->createFinancialTables();
        $this->createCommerceTables();
        $this->createPricingTables();
        $this->createBenefitTables();
        $this->createManualPaymentTables();
        $this->createPrivilegedPricingTable();
    }

    public function down(): void
    {
        Schema::dropIfExists('shop__privileged_prices');
        Schema::dropIfExists('shop__manual_refunds');
        Schema::dropIfExists('shop__manual_payments');
        Schema::dropIfExists('shop__giftcard_messages');
        Schema::dropIfExists('shop__giftcard_holds');
        Schema::dropIfExists('shop__giftcard_issuances');
        Schema::dropIfExists('shop__upgrade_allocations');
        Schema::dropIfExists('shop__upgrade_states');
        Schema::dropIfExists('shop__upgrade_groups');
        Schema::dropIfExists('shop__document_messages');
        Schema::dropIfExists('shop__invoice_sequences');
        Schema::dropIfExists('shop__receipts');
        Schema::dropIfExists('shop__receipt_sequences');
        Schema::dropIfExists('shop__credit_recoveries');
        Schema::dropIfExists('shop__financial_events');
        Schema::dropIfExists('shop__wallet_returns');
        Schema::dropIfExists('shop__disputes');
        Schema::dropIfExists('shop__refunds');
        Schema::dropIfExists('shop__subscription_changes');
        Schema::dropIfExists('shop__subscription_trials');
        Schema::dropIfExists('shop__subscription_payments');
        Schema::dropIfExists('shop__subscriptions');
        Schema::dropIfExists('shop__deliveries');
        Schema::dropIfExists('shop__product_codes');
        Schema::dropIfExists('shop__role_grants');
        Schema::dropIfExists('shop__payment_events');
        Schema::dropIfExists('shop__order_items');
        Schema::dropIfExists('shop__orders');
        Schema::dropIfExists('shop__product_servers');
        Schema::dropIfExists('shop__coupon_usage_baselines');
        Schema::dropIfExists('shop__giftcards');
        Schema::dropIfExists('shop__coupons');
        Schema::dropIfExists('shop__products');
        Schema::dropIfExists('shop__categories');
        Schema::dropIfExists('shop__guest_sessions');
        Schema::dropIfExists('shop__guest_challenges');
        Schema::dropIfExists('shop__guests');
    }

    private function createGuestTables(): void
    {
        Schema::create('shop__guests', function (Blueprint $table) {
            $table->id();
            $table->text('email');
            $table->string('email_hash', 64)->unique();
            $table->string('locale', 12);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
        Schema::create('shop__guest_challenges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('guest_id')->constrained('shop__guests')->restrictOnDelete();
            $table->string('code_hash', 64);
            $table->string('browser_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at');
            $table->index(['guest_id', 'expires_at']);
        });
        Schema::create('shop__guest_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('guest_id')->constrained('shop__guests')->restrictOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('created_at');
            $table->index(['guest_id', 'expires_at']);
        });
    }

    private function createCatalogTables(): void
    {
        Schema::create('shop__categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('shop__categories')->nullOnDelete();
            $table->foreignId('image_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->string('name', 120);
            $table->string('locale', 12);
            $table->text('description')->nullable();
            $table->json('translations')->nullable();
            $table->boolean('exclusive')->default(false);
            $table->unsignedInteger('position')->default(0);
        });
        Schema::create('shop__products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained('shop__categories')->nullOnDelete();
            $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->unsignedBigInteger('server_id')->nullable()->index();
            $table->foreignId('image_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->string('kind', 20)->default('product');
            $table->string('game', 40)->nullable();
            $table->string('locale', 12);
            $table->string('slug', 180)->nullable();
            $table->string('name', 180);
            $table->longText('description');
            $table->json('translations')->nullable();
            $table->text('search_text')->nullable();
            $table->unsignedInteger('price_minor');
            $table->string('currency', 3)->default('EUR');
            $table->boolean('custom_price')->default(false);
            $table->unsignedInteger('minimum_price_minor')->nullable();
            $table->unsignedInteger('maximum_price_minor')->nullable();
            $table->unsignedInteger('wallet_credit_minor')->default(0);
            $table->string('wallet_currency', 3)->nullable();
            $table->unsignedInteger('stock')->nullable();
            $table->unsignedInteger('limit_per_user')->nullable();
            $table->unsignedInteger('global_limit')->nullable();
            $table->string('limit_period', 8)->nullable();
            $table->string('required_permission', 100)->nullable();
            $table->string('billing_interval', 12)->nullable();
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->boolean('trial_requires_payment_method')->default(true);
            $table->string('subscription_group', 80)->nullable();
            $table->unsignedInteger('role_days')->nullable();
            $table->string('upgrade_group', 80)->nullable();
            $table->unsignedInteger('upgrade_weight')->default(0);
            $table->text('commands')->nullable();
            $table->text('refund_commands')->nullable();
            $table->json('variables')->nullable();
            $table->string('download_path')->nullable();
            $table->boolean('uses_codes')->default(false);
            $table->unsignedInteger('giftcard_days')->nullable();
            $table->boolean('requires_online')->default(false);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->index(['enabled', 'locale', 'position']);
            $table->unique(['game', 'slug']);
            $table->index(['game', 'enabled', 'position']);
        });
        Schema::create('shop__coupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->foreignId('issuer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('code', 60)->unique();
            $table->string('type', 12);
            $table->unsignedInteger('amount');
            $table->string('currency', 3)->default('EUR');
            $table->unsignedInteger('minimum_minor')->default(0);
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('per_user_limit')->nullable();
            $table->string('period', 8)->nullable();
            $table->json('product_ids')->nullable();
            $table->json('category_ids')->nullable();
            $table->json('issuer_permissions')->nullable();
            $table->integer('issuer_position')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
        });
        Schema::create('shop__giftcards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('recipient_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('issuer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('source_item_id')->nullable()->index();
            $table->string('code_hash', 64)->unique();
            $table->string('last_four', 4);
            $table->text('secret')->nullable();
            $table->text('claim_secret')->nullable();
            $table->string('claim_token_hash', 64)->nullable()->unique();
            $table->unsignedInteger('balance_minor');
            $table->string('currency', 3);
            $table->json('issuer_permissions')->nullable();
            $table->integer('issuer_position')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('created_at');
            $table->index(['owner_id', 'created_at']);
        });
        Schema::create('shop__coupon_usage_baselines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('shop__coupons')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_key', 64)->unique();
            $table->unsignedInteger('uses');
            $table->timestamp('occurred_at')->nullable();
            $table->index(['coupon_id', 'user_id']);
        });
        Schema::create('shop__product_servers', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained('shop__products')->cascadeOnDelete();
            $table->foreignId('server_id')->constrained('servers')->cascadeOnDelete();
            $table->primary(['product_id', 'server_id']);
        });
    }

    private function createOrderTables(): void
    {
        Schema::create('shop__orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('guest_id')->nullable()->constrained('shop__guests')->restrictOnDelete();
            $table->foreignId('coupon_id')->nullable()->constrained('shop__coupons')->nullOnDelete();
            $table->foreignId('giftcard_id')->nullable()->constrained('shop__giftcards')->nullOnDelete();
            $table->uuid('reference')->unique();
            $table->uuid('checkout_key')->unique();
            $table->string('checkout_hash', 64)->nullable();
            $table->string('provider', 32);
            $table->string('provider_reference', 180)->nullable();
            $table->string('payment_reference', 180)->nullable();
            $table->string('payment_kind', 20)->nullable();
            $table->text('checkout_url')->nullable();
            $table->text('payment_instructions')->nullable();
            $table->string('currency', 3);
            $table->unsignedInteger('subtotal_minor');
            $table->unsignedInteger('discount_minor')->default(0);
            $table->unsignedInteger('upgrade_credit_minor')->default(0);
            $table->unsignedInteger('giftcard_minor')->default(0);
            $table->unsignedInteger('wallet_minor')->default(0);
            $table->unsignedInteger('total_minor');
            $table->unsignedInteger('refunded_minor')->default(0);
            $table->json('commercial_snapshot')->nullable();
            $table->boolean('recurring')->default(false);
            $table->boolean('auto_expire')->default(true);
            $table->boolean('financial_hold')->default(false);
            $table->boolean('financial_review')->default(false);
            $table->boolean('pricing_hold')->default(false);
            $table->string('pricing_review_reason', 32)->nullable();
            $table->boolean('giftcard_hold')->default(false);
            $table->string('status', 20)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'expires_at']);
            $table->index(['user_id', 'created_at']);
            $table->unique(['provider', 'provider_reference']);
            $table->unique(['provider', 'payment_reference']);
            $table->index(['paid_at', 'currency'], 'shop__orders_paid_at_currency_index');
            $table->index(['created_at'], 'shop__orders_created_at_index');
        });
        Schema::create('shop__order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('shop__orders')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('shop__products')->nullOnDelete();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->unsignedBigInteger('server_id')->nullable()->index();
            $table->foreignId('recipient_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('giftcard_recipient_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('kind', 20)->default('product');
            $table->string('game', 40)->nullable()->index();
            $table->string('player_uuid', 64)->nullable();
            $table->string('player_name', 60)->nullable();
            $table->string('name', 180);
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('unit_minor');
            $table->unsignedInteger('promotion_minor')->default(0);
            $table->unsignedInteger('upgrade_credit_minor')->default(0);
            $table->unsignedInteger('paid_minor')->nullable();
            $table->json('pricing_snapshot')->nullable();
            $table->json('discount_snapshot')->nullable();
            $table->unsignedInteger('wallet_credit_minor')->default(0);
            $table->string('wallet_currency', 3)->nullable();
            $table->string('billing_interval', 12)->nullable();
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->boolean('trial_requires_payment_method')->default(true);
            $table->string('subscription_group', 80)->nullable();
            $table->unsignedInteger('role_days')->nullable();
            $table->string('upgrade_group', 80)->nullable();
            $table->unsignedInteger('upgrade_weight')->default(0);
            $table->text('commands')->nullable();
            $table->text('refund_commands')->nullable();
            $table->text('command_identity')->nullable();
            $table->json('variables')->nullable();
            $table->string('download_path')->nullable();
            $table->unsignedInteger('giftcard_days')->nullable();
            $table->boolean('requires_online')->default(false);
            $table->index(['order_id']);
        });
        Schema::create('shop__payment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('shop__orders')->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('event_id', 180);
            $table->timestamp('created_at');
            $table->unique(['provider', 'event_id']);
        });
        Schema::create('shop__role_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('shop__orders')->cascadeOnDelete();
            $table->string('revocation_reason', 24)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('created_at');
            $table->unique(['user_id', 'role_id', 'order_id']);
            $table->index(['user_id', 'revoked_at', 'expires_at']);
        });
        Schema::create('shop__product_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('shop__products')->cascadeOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained('shop__order_items')->restrictOnDelete();
            $table->string('code_hash', 64)->unique();
            $table->text('value');
            $table->timestamp('created_at');
            $table->index(['product_id', 'order_item_id']);
        });
    }

    private function createDeliveryTables(): void
    {
        Schema::create('shop__deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('order_id')->constrained('shop__orders')->cascadeOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained('shop__order_items')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('guest_id')->nullable()->constrained('shop__guests')->restrictOnDelete();
            $table->unsignedBigInteger('server_id')->nullable()->index();
            $table->unsignedBigInteger('link_server_id')->nullable()->index();
            $table->string('kind', 12)->default('grant');
            $table->string('game', 40)->nullable()->index();
            $table->string('player_uuid', 64)->nullable();
            $table->string('player_name', 60)->nullable();
            $table->text('command');
            $table->boolean('requires_online')->default(false);
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->string('lease_token', 64)->nullable();
            $table->timestamp('available_at')->nullable()->index();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('created_at');
            $table->index(['status', 'created_at']);
        });
    }

    private function createSubscriptionTables(): void
    {
        Schema::create('shop__subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('shop__orders')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('shop__products')->nullOnDelete();
            $table->string('provider', 32);
            $table->string('provider_reference', 180);
            $table->boolean('cancel_at_period_end')->default(false);
            $table->string('status', 32);
            $table->timestamp('paid_until')->nullable();
            $table->json('plan_snapshot')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_reference']);
            $table->index(['user_id', 'status']);
        });
        Schema::create('shop__subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('shop__subscriptions')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('shop__orders')->restrictOnDelete();
            $table->string('provider_payment', 180)->unique();
            $table->timestamp('period_end');
            $table->timestamp('created_at');
        });
        Schema::create('shop__subscription_trials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('shop__orders')->restrictOnDelete();
            $table
                ->foreignId('subscription_id')
                ->nullable()
                ->unique()
                ->constrained('shop__subscriptions')
                ->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->restrictOnDelete();
            $table->string('family', 64);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'family']);
            $table->unique(['recipient_id', 'family']);
        });
        Schema::create('shop__subscription_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('subscription_id')->constrained('shop__subscriptions')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('shop__products')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('shop__orders')->restrictOnDelete();
            $table->string('status', 20);
            $table->unsignedInteger('amount_minor');
            $table->string('provider_price', 180);
            $table->string('provider_reference', 180)->nullable();
            $table->text('checkout_url')->nullable();
            $table->json('snapshot');
            $table->json('funding_snapshot')->nullable();
            $table->timestamp('proration_at');
            $table->timestamp('effective_at');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['subscription_id', 'status']);
            $table->unique(['subscription_id', 'provider_reference'], 'shop__subscription_changes_provider_unique');
        });
    }

    private function createFinancialTables(): void
    {
        Schema::create('shop__refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('order_id')->constrained('shop__orders')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('request_key')->unique();
            $table->string('provider', 32);
            $table->string('payment_reference', 180)->nullable();
            $table->string('provider_reference', 180)->nullable();
            $table->string('currency', 3);
            $table->unsignedInteger('amount_minor');
            $table->unsignedInteger('external_minor');
            $table->unsignedInteger('giftcard_minor')->default(0);
            $table->unsignedInteger('wallet_minor')->default(0);
            $table->string('reason', 255);
            $table->string('status', 20)->default('pending');
            $table->string('error', 255)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_reference']);
            $table->index(['order_id', 'status']);
        });
        Schema::create('shop__disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('shop__orders')->restrictOnDelete();
            $table->string('provider', 32);
            $table->string('provider_reference', 180);
            $table->string('payment_reference', 180);
            $table->string('currency', 3);
            $table->unsignedInteger('amount_minor');
            $table->string('status', 12);
            $table->timestamps();
            $table->unique(['provider', 'provider_reference']);
            $table->index(['order_id', 'status']);
        });
        Schema::create('shop__wallet_returns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('order_id')->constrained('shop__orders')->restrictOnDelete();
            $table->uuid('refund_id')->nullable();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('source_key', 100)->unique();
            $table->string('transaction_reference', 180)->nullable();
            $table->string('currency', 3);
            $table->unsignedInteger('amount_minor');
            $table->string('status', 20)->default('pending');
            $table->string('error', 40)->nullable();
            $table->timestamps();
            $table->foreign('refund_id')->references('id')->on('shop__refunds')->restrictOnDelete();
            $table->index(['status', 'created_at']);
            $table->index(['order_id', 'status']);
        });
        Schema::create('shop__financial_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('shop__orders')->restrictOnDelete();
            $table->string('event_key', 255)->unique();
            $table->string('type', 32);
            $table->string('reference', 180);
            $table->string('currency', 3);
            $table->unsignedInteger('amount_minor');
            $table->string('status', 20);
            $table->timestamp('created_at');
        });
        Schema::create('shop__credit_recoveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->unique()->constrained('shop__order_items')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('shop__orders')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('recovered_minor')->default(0);
            $table->timestamps();
        });
    }

    private function createCommerceTables(): void
    {
        Schema::create('shop__receipt_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->string('number_prefix', 12)->nullable();
            $table->unsignedBigInteger('next_number')->default(1);
        });
        Schema::create('shop__receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('shop__orders')->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('shop__receipts')->restrictOnDelete();
            $table->string('number', 80)->unique();
            $table->string('source_key', 160)->unique();
            $table->string('kind', 12);
            $table->string('currency', 3);
            $table->unsignedInteger('gross_minor');
            $table->unsignedInteger('tax_minor');
            $table->unsignedInteger('net_minor');
            $table->unsignedSmallInteger('rate_bps')->default(0);
            $table->json('snapshot');
            $table->longText('body');
            $table->string('body_hash', 64);
            $table->timestamp('issued_at');
            $table->index(['order_id', 'issued_at']);
        });
        Schema::create('shop__invoice_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->string('number_prefix', 12);
            $table->unsignedBigInteger('next_number')->default(1);
        });
        Schema::create('shop__document_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_id')->unique()->constrained('shop__receipts')->restrictOnDelete();
            $table->string('address_hash', 64)->nullable();
            $table->boolean('automatic')->default(true);
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('attempt')->default(1);
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'queued_at']);
        });
    }

    private function createPricingTables(): void
    {
        Schema::create('shop__upgrade_groups', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80);
            $table->string('name', 120);
            $table->string('game', 40);
            $table->string('currency', 3);
            $table->string('strategy', 32)->default('net-paid');
            $table->string('expired_policy', 12)->default('reset');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['game', 'currency', 'slug']);
        });
        Schema::create('shop__upgrade_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('group_id')->constrained('shop__upgrade_groups')->restrictOnDelete();
            $table->foreignId('current_item_id')->nullable()->constrained('shop__order_items')->restrictOnDelete();
            $table->foreignId('pending_order_id')->nullable()->constrained('shop__orders')->restrictOnDelete();
            $table->unsignedBigInteger('version')->default(0);
            $table->unsignedInteger('weight')->default(0);
            $table->string('status', 12)->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'group_id']);
        });
        Schema::create('shop__upgrade_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('target_item_id')->constrained('shop__order_items')->restrictOnDelete();
            $table->foreignId('source_item_id')->constrained('shop__order_items')->restrictOnDelete();
            $table->unsignedInteger('retained_minor');
            $table->unsignedInteger('credited_minor');
            $table->timestamp('created_at');
            $table->unique(['target_item_id', 'source_item_id'], 'shop__upgrade_allocation_unique');
            $table->index('source_item_id');
        });
    }

    private function createBenefitTables(): void
    {
        Schema::create('shop__giftcard_issuances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained('shop__order_items')->restrictOnDelete();
            $table->foreignId('giftcard_id')->unique()->constrained('shop__giftcards')->restrictOnDelete();
            $table->unsignedTinyInteger('unit_number');
            $table->unsignedInteger('face_minor');
            $table->timestamp('created_at');
            $table->unique(['order_item_id', 'unit_number'], 'shop__giftcard_issuance_unit_unique');
        });
        Schema::create('shop__giftcard_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('giftcard_id')->constrained('shop__giftcards')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('shop__orders')->restrictOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['giftcard_id', 'order_id']);
            $table->index(['order_id', 'active']);
        });
        Schema::create('shop__giftcard_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('giftcard_id')->constrained('shop__giftcards')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('event', 16);
            $table->string('address_hash', 64);
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('attempt')->default(1);
            $table->timestamp('consented_at');
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->unique(['giftcard_id', 'user_id', 'event'], 'shop__giftcard_message_event_unique');
            $table->index(['status', 'queued_at']);
        });
    }

    private function createManualPaymentTables(): void
    {
        Schema::create('shop__manual_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('order_id')->constrained('shop__orders')->restrictOnDelete();
            $table->unique('order_id');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference', 120);
            $table->string('reference_hash', 64)->unique();
            $table->unsignedInteger('amount_minor');
            $table->string('currency', 3);
            $table->text('note');
            $table->timestamp('confirmed_at');
        });
        Schema::create('shop__manual_refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('order_id')->constrained('shop__orders')->restrictOnDelete();
            $table->foreignUuid('refund_id')->unique()->constrained('shop__refunds')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference', 120);
            $table->string('reference_hash', 64)->unique();
            $table->unsignedInteger('amount_minor');
            $table->string('currency', 3);
            $table->text('note');
            $table->timestamp('confirmed_at');
        });
    }

    private function createPrivilegedPricingTable(): void
    {
        Schema::create('shop__privileged_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('shop__categories')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('shop__products')->cascadeOnDelete();
            $table->foreignId('issuer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->string('game', 40);
            $table->string('currency', 3);
            $table->string('scope', 12);
            $table->string('type', 12);
            $table->unsignedInteger('amount');
            $table->boolean('combine_coupon')->default(false);
            $table->json('issuer_permissions')->nullable();
            $table->integer('issuer_position')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['game', 'currency', 'enabled', 'role_id']);
        });
    }
};
