-- =============================================================================
-- PERFORMANCE INDEXES
-- Safe to re-run: mysql -f flag skips already-existing indexes
-- =============================================================================

-- TABLE: stock_moves (critical for store revenue queries)
CREATE INDEX idx_tran_date_type       ON 0_stock_moves (tran_date, type);
CREATE INDEX idx_stock_tran_date      ON 0_stock_moves (stock_id, tran_date);
CREATE INDEX idx_loc_tran_date        ON 0_stock_moves (loc_code, tran_date);

-- TABLE: debtor_trans (critical for getTransactions)
CREATE INDEX idx_debtor_trans_tran_date  ON 0_debtor_trans (tran_date);
CREATE INDEX idx_debtor_trans_type       ON 0_debtor_trans (type);
CREATE INDEX idx_debtor_trans_debtor_no  ON 0_debtor_trans (debtor_no);
CREATE INDEX idx_debtor_trans_trans_no   ON 0_debtor_trans (trans_no);

-- TABLE: users
CREATE UNIQUE INDEX users_user_id_unique  ON 0_users (user_id);
CREATE        INDEX users_user_id_index   ON 0_users (user_id);
CREATE UNIQUE INDEX users_email_unique    ON 0_users (email);
CREATE        INDEX users_role_id_index   ON 0_users (role_id);
CREATE        INDEX users_inactive_index  ON 0_users (inactive);

-- TABLE: sessions
CREATE INDEX sessions_user_id_index       ON 0_sessions (user_id);
CREATE INDEX sessions_last_activity_index ON 0_sessions (last_activity);

-- TABLE: cache
CREATE INDEX cache_expiration_index ON 0_cache (expiration);
ALTER TABLE 0_cache MODIFY value LONGTEXT COLLATE utf8mb4_unicode_ci NOT NULL;

-- TABLE: jobs
CREATE INDEX jobs_queue_index ON 0_jobs (queue);

-- TABLE: failed_jobs
CREATE UNIQUE INDEX failed_jobs_uuid_unique ON 0_failed_jobs (uuid);

-- TABLE: permissions
CREATE UNIQUE INDEX permissions_name_unique ON 0_permissions (name);

-- TABLE: roles
CREATE UNIQUE INDEX roles_name_unique ON 0_roles (name);

-- TABLE: model_has_permissions
CREATE INDEX model_has_permissions_model_id_model_type_index ON 0_model_has_permissions (model_id, model_type);

-- TABLE: model_has_roles
CREATE INDEX model_has_roles_model_id_model_type_index ON 0_model_has_roles (model_id, model_type);

-- TABLE: oauth_access_tokens
CREATE INDEX oauth_access_tokens_user_id_index ON 0_oauth_access_tokens (user_id);

-- TABLE: oauth_refresh_tokens
CREATE INDEX oauth_refresh_tokens_access_token_id_index ON 0_oauth_refresh_tokens (access_token_id);

-- TABLE: gl_accounts
CREATE UNIQUE INDEX gl_accounts_code_unique ON 0_gl_accounts (code);

-- TABLE: gl_account_groups
CREATE UNIQUE INDEX gl_account_groups_code_unique ON 0_gl_account_groups (code);

-- TABLE: dimensions
CREATE UNIQUE INDEX dimensions_reference_unique ON 0_dimensions (reference);

-- TABLE: journals
CREATE INDEX journals_tran_date_index ON 0_journals (tran_date);

-- TABLE: gld_transactions
CREATE INDEX Type_and_Number       ON 0_gld_transactions (type, type_no);
CREATE INDEX gld_tran_date         ON 0_gld_transactions (tran_date);
CREATE INDEX account_and_tran_date ON 0_gld_transactions (account, tran_date);
CREATE INDEX gl_tid_idx            ON 0_gld_transactions (tid);

-- TABLE: purchase_orders
CREATE UNIQUE INDEX purchase_orders_po_no_unique      ON 0_purchase_orders (po_no);
CREATE        INDEX purchase_orders_supplier_id_index ON 0_purchase_orders (supplier_id);

-- TABLE: purchase_requisitions
CREATE UNIQUE INDEX purchase_requisitions_pr_no_unique ON 0_purchase_requisitions (pr_no);

-- TABLE: purchase_quotations
CREATE UNIQUE INDEX purchase_quotations_quotation_no_unique ON 0_purchase_quotations (quotation_no);
CREATE        INDEX purchase_quotations_pr_id_index         ON 0_purchase_quotations (pr_id);

-- TABLE: inventory_transfers
CREATE UNIQUE INDEX inventory_transfers_reference_unique ON 0_inventory_transfers (reference);

-- TABLE: inventory_adjustments
CREATE UNIQUE INDEX inventory_adjustments_reference_unique ON 0_inventory_adjustments (reference);

-- TABLE: stock_requisitions
CREATE UNIQUE INDEX stock_requisitions_reference_unique ON 0_stock_requisitions (reference);

-- TABLE: stock_take_headers
CREATE UNIQUE INDEX stock_take_headers_reference_unique ON 0_stock_take_headers (reference);

-- TABLE: pos_transactions
CREATE UNIQUE INDEX pos_transactions_transaction_no_unique ON 0_pos_transactions (transaction_no);

-- TABLE: suppliers
CREATE INDEX idxSupplierMemberNumber ON 0_suppliers (memberNumber);
CREATE INDEX idxSupplierType         ON 0_suppliers (supplierType);
CREATE INDEX idxSupplierId           ON 0_suppliers (supplierId);

-- TABLE: inventory_locations
CREATE UNIQUE INDEX inventory_locations_code_unique ON 0_inventory_locations (code);

-- TABLE: sales_kits
CREATE UNIQUE INDEX sales_kits_code_unique ON 0_sales_kits (code);

-- TABLE: transaction_references
CREATE UNIQUE INDEX transaction_references_trans_type_unique ON 0_transaction_references (trans_type);

-- =============================================================================
-- END
-- =============================================================================
