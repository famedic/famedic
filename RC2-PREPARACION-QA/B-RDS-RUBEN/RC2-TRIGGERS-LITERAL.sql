-- Extracto literal de database/migrations/2026_11_08_220000_create_opaque_medical_benefit_store.php
-- Solo para revisión DBA. No ejecutar fuera de una ventana aprobada.

DROP TRIGGER IF EXISTS obi_insert;
CREATE TRIGGER obi_insert AFTER INSERT ON medical_service_cart_items FOR EACH ROW BEGIN UPDATE medical_service_carts SET opaque_revision=opaque_revision+1 WHERE id=NEW.cart_id; END;

DROP TRIGGER IF EXISTS obi_delete;
CREATE TRIGGER obi_delete AFTER DELETE ON medical_service_cart_items FOR EACH ROW BEGIN UPDATE medical_service_carts SET opaque_revision=opaque_revision+1 WHERE id=OLD.cart_id; END;

DROP TRIGGER IF EXISTS obi_update;
CREATE TRIGGER obi_update AFTER UPDATE ON medical_service_cart_items FOR EACH ROW BEGIN UPDATE medical_service_carts SET opaque_revision=opaque_revision+1 WHERE id=OLD.cart_id OR id=NEW.cart_id; END;

DROP TRIGGER IF EXISTS obb_insert;
CREATE TRIGGER obb_insert AFTER INSERT ON medical_service_cart_benefits FOR EACH ROW BEGIN UPDATE medical_service_carts SET opaque_revision=opaque_revision+1 WHERE id=NEW.cart_id; END;

DROP TRIGGER IF EXISTS obb_delete;
CREATE TRIGGER obb_delete AFTER DELETE ON medical_service_cart_benefits FOR EACH ROW BEGIN UPDATE medical_service_carts SET opaque_revision=opaque_revision+1 WHERE id=OLD.cart_id; END;

DROP TRIGGER IF EXISTS obb_update;
CREATE TRIGGER obb_update AFTER UPDATE ON medical_service_cart_benefits FOR EACH ROW BEGIN UPDATE medical_service_carts SET opaque_revision=opaque_revision+1 WHERE id=OLD.cart_id OR id=NEW.cart_id; END;

DROP TRIGGER IF EXISTS ob_source;
CREATE TRIGGER ob_source BEFORE UPDATE ON coupons FOR EACH ROW SET NEW.opaque_revision=OLD.opaque_revision+1;

DROP TRIGGER IF EXISTS ob_assignment;
CREATE TRIGGER ob_assignment BEFORE UPDATE ON coupon_user FOR EACH ROW SET NEW.opaque_revision=OLD.opaque_revision+1;

DROP TRIGGER IF EXISTS obc_update;
CREATE TRIGGER obc_update BEFORE UPDATE ON medical_service_carts FOR EACH ROW BEGIN IF NOT(OLD.status <=> NEW.status) OR NOT(OLD.subtotal_cents <=> NEW.subtotal_cents) OR NOT(OLD.discount_cents <=> NEW.discount_cents) OR NOT(OLD.credit_applied_cents <=> NEW.credit_applied_cents) OR NOT(OLD.total_cents <=> NEW.total_cents) THEN SET NEW.opaque_revision=OLD.opaque_revision+1; END IF; END;
