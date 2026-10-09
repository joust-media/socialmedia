-- Test harness only (tests/ is never deployed).
--
-- The base tables that existed before migrate.php: everything else (modules, company_modules,
-- activity_log, meta, emails, pages, tire_series, library_images, drive_*, the extra post /
-- company / tire columns, the posts Draft status …) is created by migrate.php, which
-- tests/bootstrap.sh runs right after loading this file — so the harness also proves that
-- migrate.php upgrades a pre-migration database cleanly and is safe to re-run.
--
-- Column lists are derived from what the app reads and writes (INSERT / UPDATE / SELECT in
-- the PHP), with the ON DELETE CASCADE foreign keys status.php / add-post.php rely on
-- ("CASCADE deletes post_images and post_categories").
--
-- Timestamps follow the REAL production tables, not what would be tidy: the original code (the first upload) only ever
-- inserts tires (name) and tire_images (tire_id, image_url, caption, sort_order) and never reads a created_at on
-- companies / tires / tire_images / post_images, and migrate.php 17 already hedges "older deployments may have only
-- one of them". Production tire_images has NO created_at (updated_at comes from migrate.php 12) — a query that assumes
-- one fails there with "Unknown column 'created_at'" (the first Redo-queue migrate.php 52 did). Keep these minimal so
-- the suite fails the same way production would. status / client_comment on tire_images were added by hand before
-- migrate.php existed (production's own error_log shows tires.php failing on them first), so they stay.
--
-- activity_log is NOT here on purpose: production got it from migrate.php 13 exactly like this harness does, so the
-- harness has production's columns — id, company_id, entity_type, entity_id, action, actor, batch_id, summary, detail,
-- digest_id, created_at (13) + author_user_id, internal (37) + client_contact_id (44) + edited_at, deleted_at (53,
-- comment editing). The live code on main writes company_id / entity_type / entity_id / action / actor /
-- author_user_id / internal / batch_id / summary / detail / client_contact_id and reads id + created_at (the feed, the
-- Morning summary, escalation) — nothing else. Comment editing keeps to those; its own tables (comment_revisions,
-- comment_slack) carry their own created_at.
--
-- The Trash columns (trashed_at, trashed_by, trash_note + ix_trashed on posts, emails, pages, tire_images,
-- library_images) are NOT here either: migrate.php 54 adds them, exactly as on production.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS post_categories, tire_categories, post_images, posts, tire_images, tires, categories, tasks, companies;

CREATE TABLE companies (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    slug VARCHAR(80) NOT NULL UNIQUE,
    feature_label VARCHAR(80) NULL,
    logo_url VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE posts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    caption TEXT,
    hashtags TEXT,
    scheduled_date DATETIME NULL,
    status ENUM('pending','approved','denied') NOT NULL DEFAULT 'pending',
    client_comment TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_posts_company (company_id),
    CONSTRAINT fk_posts_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE post_images (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    post_id INT UNSIGNED NOT NULL,
    image_url VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    KEY idx_post_images_post (post_id),
    CONSTRAINT fk_post_images_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE tires (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE tire_images (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tire_id INT UNSIGNED NOT NULL,
    image_url VARCHAR(255) NOT NULL,
    caption TEXT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    status ENUM('pending','approved','denied') NOT NULL DEFAULT 'pending',
    client_comment TEXT NULL,
    KEY idx_tire_images_tire (tire_id),
    CONSTRAINT fk_tire_images_tire FOREIGN KEY (tire_id) REFERENCES tires(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE post_categories (
    post_id INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (post_id, category_id),
    CONSTRAINT fk_post_categories_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE tire_categories (
    tire_id INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (tire_id, category_id),
    CONSTRAINT fk_tire_categories_tire FOREIGN KEY (tire_id) REFERENCES tires(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE tasks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    status ENUM('open','in_progress','done') NOT NULL DEFAULT 'open',
    priority VARCHAR(20) NOT NULL DEFAULT 'normal',
    created_by VARCHAR(40) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    completed_at DATETIME NULL,
    KEY idx_tasks_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- migrate.php backfills un-scoped tires to the first company and refuses to run without one
-- (production always had Kenda). tests/seed.php rewrites the companies table afterwards.
INSERT INTO companies (id, name, slug, feature_label) VALUES (1, 'Kenda Tires', 'kenda', 'Tires');

INSERT INTO categories (name, sort_order) VALUES ('Product', 1), ('Lifestyle', 2), ('Promo', 3), ('Off-Road', 4);

SET FOREIGN_KEY_CHECKS = 1;
