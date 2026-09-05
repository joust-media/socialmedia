<?php
/**
 * One-shot DB migration.
 * Hit this once in a browser after deploying.
 * Safe to re-run — every step checks first.
 */

require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
requireAdmin();

$steps = [];
$errors = [];

function columnExists(PDO $pdo, $table, $column) {
    $s = $pdo->prepare("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $s->execute([$table, $column]);
    return (int)$s->fetchColumn() > 0;
}

function tableExists(PDO $pdo, $table) {
    $s = $pdo->prepare("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
    ");
    $s->execute([$table]);
    return (int)$s->fetchColumn() > 0;
}

try {
    // 1. posts.posted — boolean flag
    if (!columnExists($pdo, 'posts', 'posted')) {
        $pdo->exec("
            ALTER TABLE posts
            ADD COLUMN posted TINYINT(1) NOT NULL DEFAULT 0 AFTER status
        ");
        $steps[] = "✓ Added posts.posted.";
    } else {
        $steps[] = "• posts.posted already exists — skipped.";
    }

    // 2. posts.posted_at
    if (!columnExists($pdo, 'posts', 'posted_at')) {
        $pdo->exec("
            ALTER TABLE posts
            ADD COLUMN posted_at DATETIME NULL DEFAULT NULL AFTER posted
        ");
        $steps[] = "✓ Added posts.posted_at.";
    } else {
        $steps[] = "• posts.posted_at already exists — skipped.";
    }

    // 3. modules table
    if (!tableExists($pdo, 'modules')) {
        $pdo->exec("
            CREATE TABLE modules (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                slug VARCHAR(40) NOT NULL UNIQUE,
                singular_label VARCHAR(60) NOT NULL,
                plural_label VARCHAR(60) NOT NULL,
                icon VARCHAR(16) NOT NULL DEFAULT '🗂',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $steps[] = "✓ Created `modules` table.";
    } else {
        $steps[] = "• `modules` table already exists — skipped.";
    }

    // 4. Seed the built-in 'tires' module
    $s = $pdo->prepare("SELECT id FROM modules WHERE slug = 'tires'");
    $s->execute();
    $tiresModuleId = (int)$s->fetchColumn();
    if (!$tiresModuleId) {
        $pdo->prepare("
            INSERT INTO modules (slug, singular_label, plural_label, icon)
            VALUES ('tires', 'Tire', 'Tires', '🛞')
        ")->execute();
        $tiresModuleId = (int)$pdo->lastInsertId();
        $steps[] = "✓ Seeded 'tires' module (id={$tiresModuleId}).";
    } else {
        $steps[] = "• 'tires' module already seeded (id={$tiresModuleId}).";
    }

    // 5. company_modules pivot
    if (!tableExists($pdo, 'company_modules')) {
        $pdo->exec("
            CREATE TABLE company_modules (
                company_id INT UNSIGNED NOT NULL,
                module_id  INT UNSIGNED NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                PRIMARY KEY (company_id, module_id),
                KEY (module_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $steps[] = "✓ Created `company_modules` pivot.";
    } else {
        $steps[] = "• `company_modules` already exists — skipped.";
    }

    // 6. tires.company_id
    if (!columnExists($pdo, 'tires', 'company_id')) {
        $pdo->exec("
            ALTER TABLE tires
            ADD COLUMN company_id INT UNSIGNED NULL DEFAULT NULL AFTER id,
            ADD INDEX idx_tires_company (company_id)
        ");
        $steps[] = "✓ Added tires.company_id (nullable for backfill).";
    } else {
        $steps[] = "• tires.company_id already exists — skipped.";
    }

    // 7. tires.module_id
    if (!columnExists($pdo, 'tires', 'module_id')) {
        $pdo->exec("
            ALTER TABLE tires
            ADD COLUMN module_id INT UNSIGNED NOT NULL DEFAULT {$tiresModuleId}
                AFTER company_id,
            ADD INDEX idx_tires_module (module_id)
        ");
        $steps[] = "✓ Added tires.module_id (default = tires).";
    } else {
        $steps[] = "• tires.module_id already exists — skipped.";
    }

    // 8. Pick a default company for un-scoped tires (lowest company id)
    $firstCompany = $pdo->query("SELECT id, name FROM companies ORDER BY id ASC LIMIT 1")->fetch();
    if (!$firstCompany) {
        $errors[] = 'No companies in the `companies` table — create one before running this migration again.';
    } else {
        $defaultCoId   = (int)$firstCompany['id'];
        $defaultCoName = $firstCompany['name'];

        // 9. Backfill tires.company_id for any rows that are NULL
        $s = $pdo->prepare("UPDATE tires SET company_id = ? WHERE company_id IS NULL");
        $s->execute([$defaultCoId]);
        $rows = $s->rowCount();
        if ($rows > 0) {
            $steps[] = "✓ Backfilled {$rows} tire row(s) to company '{$defaultCoName}'.";
        } else {
            $steps[] = "• No tires needed backfilling.";
        }

        // 10. Ensure tires.company_id is NOT NULL now (safe since everything is backfilled)
        $col = $pdo->query("
            SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'tires'
              AND COLUMN_NAME = 'company_id'
        ")->fetchColumn();
        if ($col === 'YES') {
            $pdo->exec("ALTER TABLE tires MODIFY company_id INT UNSIGNED NOT NULL");
            $steps[] = "✓ Made tires.company_id NOT NULL.";
        }

        // 11. Seed company_modules — enable 'tires' for every company that has tires
        $pdo->prepare("
            INSERT IGNORE INTO company_modules (company_id, module_id, sort_order)
            SELECT DISTINCT company_id, ?, 0 FROM tires
        ")->execute([$tiresModuleId]);
        $count = (int)$pdo->query("
            SELECT COUNT(*) FROM company_modules WHERE module_id = {$tiresModuleId}
        ")->fetchColumn();
        $steps[] = "✓ Enabled 'tires' module on {$count} compan" . ($count === 1 ? 'y' : 'ies') . '.';
    }

    // 11.0 posts.name — human-friendly label, shown in admin lists and activity feed.
    if (tableExists($pdo, 'posts') && !columnExists($pdo, 'posts', 'name')) {
        $pdo->exec("
            ALTER TABLE posts
            ADD COLUMN name VARCHAR(150) NULL DEFAULT NULL AFTER company_id
        ");
        $steps[] = "✓ Added posts.name.";
    } elseif (tableExists($pdo, 'posts')) {
        $steps[] = "• posts.name already exists — skipped.";
    }

    // 11a. companies.default_hashtags — client-level hashtag baseline.
    //      Pre-fills the hashtag field on every new post for the client; admins
    //      can still add/remove during edit.
    if (tableExists($pdo, 'companies') && !columnExists($pdo, 'companies', 'default_hashtags')) {
        $pdo->exec("
            ALTER TABLE companies
            ADD COLUMN default_hashtags TEXT NULL DEFAULT NULL
        ");
        $steps[] = "✓ Added companies.default_hashtags.";
    } elseif (tableExists($pdo, 'companies')) {
        $steps[] = "• companies.default_hashtags already exists — skipped.";
    }

    // 11b. tire_images.display_name — admin-editable file label.
    //     Becomes the suggested download filename and shows up in activity-log
    //     summaries instead of the bare numeric id.
    if (tableExists($pdo, 'tire_images')) {
        if (!columnExists($pdo, 'tire_images', 'display_name')) {
            $pdo->exec("
                ALTER TABLE tire_images
                ADD COLUMN display_name VARCHAR(150) NULL DEFAULT NULL AFTER caption
            ");
            $steps[] = "✓ Added tire_images.display_name.";
        } else {
            $steps[] = "• tire_images.display_name already exists — skipped.";
        }
    }

    // 12. updated_at on every editable table — auto-bumps on any UPDATE
    $tablesNeedingUpdated = ['posts', 'post_images', 'tires', 'tire_images', 'tasks'];
    foreach ($tablesNeedingUpdated as $tbl) {
        if (!tableExists($pdo, $tbl)) {
            $steps[] = "• Table `{$tbl}` does not exist — skipped updated_at.";
            continue;
        }
        if (!columnExists($pdo, $tbl, 'updated_at')) {
            // NULL default + ON UPDATE so existing rows keep NULL until they're next touched.
            // We then backfill from created_at (or NOW() if no created_at) so old rows aren't blank.
            $pdo->exec("
                ALTER TABLE `{$tbl}`
                ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL
                    ON UPDATE CURRENT_TIMESTAMP
            ");
            // Backfill so the UI has something to show before any edit happens
            if (columnExists($pdo, $tbl, 'created_at')) {
                $pdo->exec("UPDATE `{$tbl}` SET updated_at = created_at WHERE updated_at IS NULL");
            } else {
                $pdo->exec("UPDATE `{$tbl}` SET updated_at = NOW() WHERE updated_at IS NULL");
            }
            $steps[] = "✓ Added {$tbl}.updated_at (auto-bumps on UPDATE).";
        } else {
            $steps[] = "• {$tbl}.updated_at already exists — skipped.";
        }
    }

    // 13. activity_log — feed + digest source of truth
    if (!tableExists($pdo, 'activity_log')) {
        $pdo->exec("
            CREATE TABLE activity_log (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                company_id INT UNSIGNED NOT NULL,
                entity_type VARCHAR(20) NOT NULL,
                entity_id INT UNSIGNED NOT NULL,
                action VARCHAR(40) NOT NULL,
                actor VARCHAR(10) NOT NULL DEFAULT 'unknown',
                batch_id CHAR(16) NULL,
                summary VARCHAR(500) NOT NULL,
                detail TEXT NULL,
                digest_id INT UNSIGNED NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_recent (created_at),
                KEY idx_company_recent (company_id, created_at),
                KEY idx_entity_action (entity_type, entity_id, action, created_at),
                KEY idx_unsent (digest_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $steps[] = "✓ Created `activity_log` table.";
    } else {
        $steps[] = "• `activity_log` already exists — skipped.";
    }

    // 14. digest_runs — one row per email digest sent
    if (!tableExists($pdo, 'digest_runs')) {
        $pdo->exec("
            CREATE TABLE digest_runs (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                sent_at DATETIME NOT NULL,
                event_count INT UNSIGNED NOT NULL,
                recipient VARCHAR(120) NOT NULL,
                trigger_source ENUM('cron','manual','opportunistic') NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $steps[] = "✓ Created `digest_runs` table.";
    } else {
        $steps[] = "• `digest_runs` already exists — skipped.";
    }

    // 15. meta — small key/value store for digest scheduling
    if (!tableExists($pdo, 'meta')) {
        $pdo->exec("
            CREATE TABLE meta (
                k VARCHAR(40) PRIMARY KEY,
                v VARCHAR(255) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $pdo->exec("
            INSERT INTO meta (k, v) VALUES
                ('last_digest_sent_at', '1970-01-01 00:00:00'),
                ('digest_lock_until',   '1970-01-01 00:00:00')
        ");
        $steps[] = "✓ Created `meta` table and seeded digest keys.";
    } else {
        // Ensure both keys exist even on partial migrations
        $pdo->exec("
            INSERT IGNORE INTO meta (k, v) VALUES
                ('last_digest_sent_at', '1970-01-01 00:00:00'),
                ('digest_lock_until',   '1970-01-01 00:00:00')
        ");
        $steps[] = "• `meta` already exists — ensured digest keys.";
    }

    // 15a. posts.post_type — 'post' (default), 'story', or 'reel'
    if (tableExists($pdo, 'posts') && !columnExists($pdo, 'posts', 'post_type')) {
        $pdo->exec("
            ALTER TABLE posts
            ADD COLUMN post_type ENUM('post','story','reel') NOT NULL DEFAULT 'post'
                AFTER status
        ");
        $steps[] = "✓ Added posts.post_type (post/story/reel).";
    } elseif (tableExists($pdo, 'posts')) {
        $steps[] = "• posts.post_type already exists — skipped.";
    }

    // 15b. companies.product_type — client-profile field, source for {{product_type}}.
    if (tableExists($pdo, 'companies') && !columnExists($pdo, 'companies', 'product_type')) {
        $pdo->exec("
            ALTER TABLE companies
            ADD COLUMN product_type VARCHAR(120) NULL DEFAULT NULL
        ");
        $steps[] = "✓ Added companies.product_type.";
    } elseif (tableExists($pdo, 'companies')) {
        $steps[] = "• companies.product_type already exists — skipped.";
    }

    // 15c. companies.industry — client-profile field, source for {{industry}}.
    if (tableExists($pdo, 'companies') && !columnExists($pdo, 'companies', 'industry')) {
        $pdo->exec("
            ALTER TABLE companies
            ADD COLUMN industry VARCHAR(120) NULL DEFAULT NULL
        ");
        $steps[] = "✓ Added companies.industry.";
    } elseif (tableExists($pdo, 'companies')) {
        $steps[] = "• companies.industry already exists — skipped.";
    }

    // 15d. prompts — global AI prompt library, reusable across every client.
    //      Categories are fixed (camera/lighting/environment/product/character).
    //      tags + compatible_models are comma-separated strings; compatible_models
    //      NULL/empty means "works with every model".
    if (!tableExists($pdo, 'prompts')) {
        $pdo->exec("
            CREATE TABLE prompts (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                category ENUM('camera','lighting','environment','product','character','references','custom','client') NOT NULL,
                name VARCHAR(150) NOT NULL,
                prompt_text TEXT NOT NULL,
                tags VARCHAR(500) NULL DEFAULT NULL,
                compatible_models VARCHAR(255) NULL DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_prompts_category (category)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $steps[] = "✓ Created `prompts` table.";
    } else {
        $steps[] = "• `prompts` table already exists — skipped.";
    }

    // 15e. Seed starter prompts — the proven Kenda "Cross Trail" product-hero
    //      recipe, split into one reusable block per category. Selecting all
    //      four in the Builder reconstructs the original prompt; each block is
    //      also reusable on its own. Idempotent: keyed on name, so re-running
    //      migrate never duplicates a prompt.
    if (tableExists($pdo, 'prompts')) {
        $seedPrompts = [
            [
                'category' => 'camera',
                'name'     => 'Front-left 3/4 hero (hub-height)',
                'prompt_text' => 'Tire standing perfectly vertical in a front-left 3/4 hero view. Rotate the tire ~40° away from camera so tread dominates while the wheel face remains visible on the right. Show ~65–70% tread and ~30–35% wheel face. Full tire visible top to bottom. Camera positioned exactly at wheel hub-center height, perfectly level with no tilt. 85–135mm full-frame equivalent, f/8, deep DOF, tack sharp front to back. No wide-angle distortion',
                'tags' => 'hero, product, 3/4 view, studio',
                'compatible_models' => null,
            ],
            [
                'category' => 'lighting',
                'name'     => 'Soft neutral studio softbox',
                'prompt_text' => 'Soft neutral studio lighting with overhead softbox key and subtle fill from camera-right. Strong tread definition, even sidewall illumination, no glare or blown highlights',
                'tags' => 'studio, softbox, product',
                'compatible_models' => null,
            ],
            [
                'category' => 'environment',
                'name'     => 'Seamless white cyclorama (catalog)',
                'prompt_text' => 'Seamless pure white #FFFFFF cyclorama. No gradient, vignette, horizon, reflections, props, overlays, or people. Premium catalog-quality product photography',
                'tags' => 'studio, white, cyclorama, catalog',
                'compatible_models' => null,
            ],
            [
                'category' => 'product',
                'name'     => 'Tire & wheel — exact reproduction + text integrity',
                'prompt_text' => '{{brand_name}} {{product_name}} {{product_type}} mounted on its wheel. Reproduce tire and wheel exactly from the reference images. Do not modify geometry, proportions, tread, sidewall profile, or detail. Mount tire on wheel, bead perfectly centered, sidewall flush to rim flange. TEXT INTEGRITY (critical): every sidewall character must be pixel-faithful to the sidewall close-up reference — all lettering, logos, DOT codes, and size specs. Do not invent, smooth, stylize, or complete text. Treat all sidewall text as fixed graphics copied directly from the sidewall reference. Tread must match the tire reference exactly',
                'tags' => 'tire, wheel, reproduction, product',
                'compatible_models' => null,
            ],
        ];
        $seedCheck = $pdo->prepare("SELECT COUNT(*) FROM prompts WHERE name = ?");
        $seedIns   = $pdo->prepare("
            INSERT INTO prompts (category, name, prompt_text, tags, compatible_models)
            VALUES (?, ?, ?, ?, ?)
        ");
        $seeded = 0;
        foreach ($seedPrompts as $sp) {
            $seedCheck->execute([$sp['name']]);
            if ((int)$seedCheck->fetchColumn() === 0) {
                $seedIns->execute([
                    $sp['category'], $sp['name'], $sp['prompt_text'],
                    $sp['tags'], $sp['compatible_models'],
                ]);
                $seeded++;
            }
        }
        if ($seeded > 0) {
            $steps[] = "✓ Seeded {$seeded} starter prompt" . ($seeded === 1 ? '' : 's')
                     . " (Cross Trail hero recipe).";
        } else {
            $steps[] = "• Starter prompts already present — skipped.";
        }
    }

    // 15f. vehicles — global vehicle library, reusable across every client.
    //      A selected vehicle feeds reference images + {{vehicle_*}} variables
    //      into the AI Builder.
    if (!tableExists($pdo, 'vehicles')) {
        $pdo->exec("
            CREATE TABLE vehicles (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                manufacturer VARCHAR(120) NOT NULL,
                model VARCHAR(120) NOT NULL,
                model_year SMALLINT UNSIGNED NULL DEFAULT NULL,
                vehicle_type VARCHAR(80) NULL DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_vehicles_manufacturer (manufacturer)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $steps[] = "✓ Created `vehicles` table.";
    } else {
        $steps[] = "• `vehicles` table already exists — skipped.";
    }

    // 15g. vehicle_images — one row per image, many per vehicle.
    //      ON DELETE CASCADE so removing a vehicle clears its image rows.
    if (!tableExists($pdo, 'vehicle_images')) {
        $pdo->exec("
            CREATE TABLE vehicle_images (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                vehicle_id INT UNSIGNED NOT NULL,
                image_url VARCHAR(255) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_vehicle_images_vehicle (vehicle_id),
                CONSTRAINT fk_vehicle_images_vehicle FOREIGN KEY (vehicle_id)
                    REFERENCES vehicles(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $steps[] = "✓ Created `vehicle_images` table.";
    } else {
        $steps[] = "• `vehicle_images` table already exists — skipped.";
    }

    // 15h. Seed the vehicle library from the HMF manufacturer/model catalog.
    //      model_year is left blank — fill it per-vehicle later if needed.
    //      Idempotent: keyed on manufacturer+model so re-running never dupes.
    if (tableExists($pdo, 'vehicles')) {
        $seedVehicles = [
            ['Arctic Cat', '650 V-Twin', 'ATV'],
            ['Honda', 'TRX 250EX', 'ATV'],
            ['Honda', 'TRX 300EX', 'ATV'],
            ['Honda', 'TRX 400EX', 'ATV'],
            ['Honda', 'TRX 700XX', 'ATV'],
            ['Honda', 'Rincon', 'ATV'],
            ['Honda', 'TRX 450R', 'ATV'],
            ['Honda', 'Foreman Rubicon', 'ATV'],
            ['Honda', 'Rancher 350', 'ATV'],
            ['Honda', 'Rancher 400', 'ATV'],
            ['Honda', 'Rancher 420', 'ATV'],
            ['Polaris', 'ACE 900', 'UTV'],
            ['Honda', 'Foreman', 'ATV'],
            ['Yamaha', 'Warrior', 'ATV'],
            ['Yamaha', 'Raptor 250', 'ATV'],
            ['Yamaha', 'Raptor 350', 'ATV'],
            ['Yamaha', 'Raptor 660', 'ATV'],
            ['Yamaha', 'Raptor 700', 'ATV'],
            ['Yamaha', 'YFZ 450', 'ATV'],
            ['Yamaha', 'Wolverine 450', 'ATV'],
            ['Yamaha', 'Rhino 660', 'UTV'],
            ['Yamaha', 'Big Bear 400', 'ATV'],
            ['Yamaha', 'Grizzly 600', 'ATV'],
            ['Yamaha', 'Grizzly 660', 'ATV'],
            ['Yamaha', 'Kodiak 400', 'ATV'],
            ['Yamaha', 'Kodiak 450', 'ATV'],
            ['Yamaha', 'Grizzly 700', 'ATV'],
            ['Kawasaki', 'KFX 700', 'ATV'],
            ['Kawasaki', 'KFX 400', 'ATV'],
            ['Kawasaki', 'KFX 450R', 'ATV'],
            ['Kawasaki', 'Prairie 360', 'ATV'],
            ['Kawasaki', 'Prairie 650', 'ATV'],
            ['Kawasaki', 'Prairie 700', 'ATV'],
            ['Kawasaki', 'Brute Force 650', 'ATV'],
            ['Polaris', 'Ranger XP 900', 'UTV'],
            ['Kawasaki', 'Brute Force 650i', 'ATV'],
            ['Kawasaki', 'Teryx', 'UTV'],
            ['KTM', 'KTM 450/525 XC', 'ATV'],
            ['Can-Am', 'DS450', 'ATV'],
            ['Can-Am', 'DS650', 'ATV'],
            ['Can-Am', 'Renegade 500', 'ATV'],
            ['Can-Am', 'Outlander 500 MAX', 'ATV'],
            ['Arctic Cat', '700 EFI', 'ATV'],
            ['Arctic Cat', 'DVX 400', 'ATV'],
            ['Suzuki', 'LTZ 400', 'ATV'],
            ['Suzuki', 'LT-R 450', 'ATV'],
            ['Suzuki', 'LTZ 250', 'ATV'],
            ['Suzuki', 'Vinson', 'ATV'],
            ['Suzuki', 'King Quad 700', 'ATV'],
            ['Suzuki', 'Eiger', 'ATV'],
            ['Polaris', 'RZR 800', 'UTV'],
            ['Polaris', 'Predator', 'ATV'],
            ['Polaris', 'Outlaw 500', 'ATV'],
            ['Polaris', 'Outlaw 525: IRS', 'ATV'],
            ['Polaris', 'Outlaw 450/525: SRA', 'ATV'],
            ['Honda', 'CBR 600', 'Motorcycle'],
            ['Honda', 'CBR 1000', 'Motorcycle'],
            ['Kawasaki', 'ZX-14', 'Motorcycle'],
            ['Kawasaki', 'ZX-10', 'Motorcycle'],
            ['Suzuki', 'GSXR 600', 'Motorcycle'],
            ['Yamaha', 'Wolverine 350', 'ATV'],
            ['Honda', 'CRF 250R', 'Dirt'],
            ['Can-Am', 'G1000 MAX', 'ATV'],
            ['Suzuki', 'DRZ110', 'Dirt'],
            ['Suzuki', 'DRZ400: Dirt', 'Dirt'],
            ['Suzuki', 'DRZ400: Street', 'Motorcycle'],
            ['Yamaha', 'YZ250F', 'Dirt'],
            ['Yamaha', 'YZ426F', 'Dirt'],
            ['Yamaha', 'YZ450F', 'Dirt'],
            ['Yamaha', 'WR450', 'Dirt'],
            ['Kawasaki', 'KX250F', 'Dirt'],
            ['Kawasaki', 'KLX110', 'Dirt'],
            ['Kawasaki', 'KLX250', 'Dirt'],
            ['KTM', '950 Adventure (S)', 'Dirt'],
            ['Yamaha', 'R6', 'Motorcycle'],
            ['Yamaha', 'R1', 'Motorcycle'],
            ['Suzuki', 'King Quad 750', 'ATV'],
            ['KTM', 'KTM 450/505 SX', 'ATV'],
            ['Kawasaki', 'Ninja 250', 'Motorcycle'],
            ['Can-Am', 'Outlander 650 MAX', 'ATV'],
            ['Honda', 'CRF 150R', 'Dirt'],
            ['Yamaha', 'Rhino 700', 'UTV'],
            ['Kawasaki', 'KX450F', 'Dirt'],
            ['Suzuki', 'RMZ250', 'Dirt'],
            ['Suzuki', 'RMZ450', 'Dirt'],
            ['Suzuki', 'GSXR 1000', 'Motorcycle'],
            ['Arctic Cat', 'Thundercat 1000', 'ATV'],
            ['Honda', 'Ruckus', 'Motorcycle'],
            ['Arctic Cat', 'Prowler 700H1', 'UTV'],
            ['Yamaha', 'Grizzly 550', 'ATV'],
            ['Honda', 'TRX 90', 'ATV'],
            ['Yamaha', 'Raptor 90', 'ATV'],
            ['Kawasaki', 'Mule 610', 'UTV'],
            ['Polaris', 'RZR S 800', 'UTV'],
            ['Yamaha', 'Grizzly 400', 'ATV'],
            ['Yamaha', 'YFZ 450R', 'ATV'],
            ['Buell', '1125', 'Motorcycle'],
            ['Can-Am', 'Spyder RS', 'Motorcycle'],
            ['KTM', '990 Adventure', 'Dirt'],
            ['Kymco', 'Mongoose 300', 'ATV'],
            ['Arctic Cat', '700 H1', 'ATV'],
            ['Arctic Cat', '400 Auto', 'ATV'],
            ['Suzuki', 'King Quad 400', 'ATV'],
            ['Yamaha', 'WR250R/X', 'Dirt'],
            ['Harley', 'Touring Models', 'Motorcycle'],
            ['Polaris', 'Sportsman 600', 'ATV'],
            ['Polaris', 'Sportsman 700', 'ATV'],
            ['Can-Am', 'DS90', 'ATV'],
            ['Buell', 'RX 1190', 'Motorcycle'],
            ['Polaris', 'Sportsman 1000', 'ATV'],
            ['Honda', 'CRF 150F', 'Dirt'],
            ['Arctic Cat', 'Prowler 1000', 'UTV'],
            ['Yamaha', 'Bruin 350', 'ATV'],
            ['Yamaha', 'Grizzly 350', 'ATV'],
            ['Polaris', 'Outlaw 90', 'ATV'],
            ['Ducati', 'Monster 696', 'Motorcycle'],
            ['Ducati', '848', 'Motorcycle'],
            ['Ducati', '1198', 'Motorcycle'],
            ['Ducati', 'Monster 1100(S)', 'Motorcycle'],
            ['Husqvarna', 'TE 610', 'Motorcycle'],
            ['Husqvarna', 'SM 450R', 'Motorcycle'],
            ['Can-Am', 'Maverick', 'UTV'],
            ['Kawasaki', 'ZX-6', 'Motorcycle'],
            ['Suzuki', 'Hayabusa', 'Motorcycle'],
            ['Polaris', 'Ranger 800 XP EFI', 'UTV'],
            ['BMW', 'K1300S', 'Motorcycle'],
            ['BMW', 'S1000RR', 'Motorcycle'],
            ['Polaris', 'Sportsman 550 X2', 'ATV'],
            ['Polaris', 'RZR 170', 'UTV'],
            ['Suzuki', 'SV 650', 'Motorcycle'],
            ['Polaris', 'Sportsman 550 XP', 'ATV'],
            ['Ducati', '1098', 'Motorcycle'],
            ['Can-Am', 'Commander 1000', 'UTV'],
            ['Honda', 'CRF 250X', 'Dirt'],
            ['Yamaha', 'Zuma', 'Motorcycle'],
            ['Polaris', 'RZR 4 800', 'UTV'],
            ['Yamaha', 'WR250F', 'Dirt'],
            ['Yamaha', 'Raptor 125', 'ATV'],
            ['Polaris', 'RZR XP 900', 'UTV'],
            ['Polaris', 'Sportsman 400', 'ATV'],
            ['Can-Am', 'Outlander 800 XMR', 'ATV'],
            ['Polaris', 'Sportsman 500', 'ATV'],
            ['Kawasaki', 'Brute Force 750', 'ATV'],
            ['Suzuki', 'King Quad 450', 'ATV'],
            ['Polaris', 'Sportsman 800 X2', 'ATV'],
            ['Honda', 'CBR 250R', 'Motorcycle'],
            ['Honda', 'TRX 250X', 'ATV'],
            ['Honda', 'Recon 250', 'ATV'],
            ['Suzuki', 'King Quad 500', 'ATV'],
            ['Can-Am', 'Outlander 1000', 'ATV'],
            ['Can-Am', 'Renegade 1000', 'ATV'],
            ['Polaris', 'RZR 570', 'UTV'],
            ['Can-Am', 'Renegade 800', 'ATV'],
            ['Can-Am', 'Outlander 800', 'ATV'],
            ['Yamaha', 'WR450 F', 'Dirt'],
            ['Polaris', 'Sportsman 800', 'ATV'],
            ['Arctic Cat', 'Wildcat 1000', 'UTV'],
            ['Can-Am', 'Outlander 400', 'ATV'],
            ['Suzuki', 'GSXR 750', 'Motorcycle'],
            ['Can-Am', 'Outlander 650', 'ATV'],
            ['Polaris', 'Sportsman 850 XP', 'ATV'],
            ['Polaris', 'Sportsman 850 X2', 'ATV'],
            ['Polaris', 'Ranger 800 Mid Size', 'UTV'],
            ['Can-Am', 'Outlander 800 MAX', 'ATV'],
            ['Can-Am', 'Outlander 500', 'ATV'],
            ['Can-Am', 'Outlander 1000 XMR', 'ATV'],
            ['Polaris', 'Scrambler XP 850', 'ATV'],
            ['Honda', 'CBR 500', 'Motorcycle'],
            ['Triumph', 'Tiger Explorer', 'Motorcycle'],
            ['Kawasaki', 'Teryx 4', 'UTV'],
            ['Kawasaki', 'Ninja 300', 'Motorcycle'],
            ['Polaris', 'RZR XP 1000', 'UTV'],
            ['Honda', 'Grom', 'Motorcycle'],
            ['Can-Am', 'Outlander 1000 MAX', 'ATV'],
            ['Can-Am', 'Outlander 650 XMR', 'ATV'],
            ['Polaris', 'Sportsman 570', 'ATV'],
            ['Yamaha', 'Grizzly 450', 'ATV'],
            ['Can-Am', 'Outlander 400 MAX', 'ATV'],
            ['Polaris', 'RZR XP 4 1000', 'UTV'],
            ['Can-Am', 'Maverick MAX', 'UTV'],
            ['Can-Am', 'Commander 800', 'UTV'],
            ['Arctic Cat', '500', 'ATV'],
            ['Arctic Cat', '650', 'ATV'],
            ['Arctic Cat', '1000', 'ATV'],
            ['Polaris', 'ACE 330', 'UTV'],
            ['Polaris', 'RZR 4 900', 'UTV'],
            ['Arctic Cat', 'Wildcat Trail', 'UTV'],
            ['Polaris', 'Scrambler XP 1000', 'ATV'],
            ['Honda', 'Rancher 420: IRS', 'ATV'],
            ['Honda', 'Pioneer 700', 'UTV'],
            ['Yamaha', 'Viking', 'UTV'],
            ['Polaris', 'RZR S 900', 'UTV'],
            ['Can-Am', 'G1000', 'ATV'],
            ['Can-Am', 'Spyder STS', 'Motorcycle'],
            ['Ducati', 'Monster 1200', 'Motorcycle'],
            ['Can-Am', 'Maverick XDS Turbo', 'UTV'],
            ['Can-Am', 'Maverick XDS', 'UTV'],
            ['Polaris', 'ACE 570', 'UTV'],
            ['Polaris', 'RZR 900 Trail', 'UTV'],
            ['Polaris', 'Sportsman ETX', 'UTV'],
            ['Yamaha', 'Wolverine', 'UTV'],
            ['Polaris', 'RZR 900 XC', 'UTV'],
            ['Can-Am', 'Spyder F3', 'Motorcycle'],
            ['Ducati', 'Scrambler', 'Motorcycle'],
            ['Yamaha', 'Kodiak 700', 'ATV'],
            ['Polaris', 'RZR S 1000', 'UTV'],
            ['Can-Am', 'Outlander 500L', 'ATV'],
            ['Can-Am', 'Outlander 570', 'ATV'],
            ['Polaris', 'RZR XP Turbo', 'UTV'],
            ['Can-Am', 'Outlander 850 XMR', 'ATV'],
            ['Can-Am', 'Outlander 570 MAX', 'ATV'],
            ['Can-Am', 'Outlander 850', 'ATV'],
            ['Polaris', 'RZR XP 4 Turbo', 'UTV'],
            ['Yamaha', 'YXZ 1000R', 'UTV'],
            ['Can-Am', 'Renegade 850', 'ATV'],
            ['Polaris', 'General', 'UTV'],
            ['Can-Am', 'Outlander 850 MAX', 'ATV'],
            ['Can-Am', 'Renegade 570', 'ATV'],
            ['Polaris', 'Sportsman 850 Touring', 'ATV'],
            ['Arctic Cat', 'Wildcat Sport', 'UTV'],
            ['Polaris', 'Ranger 900 (With OEM O2 Sensor)', 'UTV'],
            ['Honda', 'Pioneer 1000', 'UTV'],
            ['Can-Am', 'Maverick X3', 'UTV'],
            ['Hi-Sun', 'Strike 1000', 'UTV'],
            ['Polaris', 'Ranger XP 1000', 'UTV'],
            ['Can-Am', 'Outlander 570L', 'ATV'],
            ['Hi-Sun', 'Strike 1000 Crew', 'UTV'],
            ['Can-Am', 'Spyder F3 S', 'Motorcycle'],
            ['Polaris', 'Outlaw 110', 'ATV'],
            ['Polaris', 'ACE 900 XC', 'UTV'],
            ['Can-Am', 'Outlander 450', 'ATV'],
            ['Polaris', 'Sportsman 850', 'ATV'],
            ['Can-Am', 'Maverick X3 MAX', 'UTV'],
            ['Can-Am', 'Maverick Trail 1000', 'UTV'],
            ['Yamaha', 'YXZ 1000R SE', 'UTV'],
            ['Yamaha', 'YXZ 1000R SS SE', 'UTV'],
            ['Yamaha', 'YXZ 1000R SS', 'UTV'],
            ['Polaris', 'RZR RS1', 'UTV'],
            ['Arctic Cat', 'Wildcat XX', 'UTV'],
            ['Polaris', 'General 4', 'UTV'],
            ['Yamaha', 'Wolverine X2', 'UTV'],
            ['Yamaha', 'Wolverine X4', 'UTV'],
            ['Polaris', 'RZR XP Turbo S', 'UTV'],
            ['Polaris', 'Sportsman 450', 'ATV'],
            ['Polaris', 'RZR XP 4 Turbo S', 'UTV'],
            ['Can-Am', 'Maverick Sport 1000R', 'UTV'],
            ['Honda', 'Talon 1000R/X', 'UTV'],
            ['Kawasaki', 'Z125 Pro', 'Motorcycle'],
            ['Honda', 'Monkey', 'Motorcycle'],
            ['Polaris', 'Sportsman 500 EFI', 'ATV'],
            ['Polaris', 'RZR Pro XP', 'UTV'],
            ['Honda', 'Talon 1000X-4', 'UTV'],
            ['Arctic Cat', '400 Manual', 'ATV'],
            ['Polaris', 'Scrambler XP 1000 S', 'ATV'],
            ['Polaris', 'Sportsman XP 1000 S', 'ATV'],
            ['Polaris', 'Ranger 1000', 'UTV'],
            ['Kawasaki', 'Teryx KRX 1000', 'UTV'],
            ['Polaris', 'RZR Turbo R', 'UTV'],
            ['Polaris', 'RZR Pro R', 'UTV'],
            ['Can-Am', 'Renegade 650', 'ATV'],
            ['Can-Am', 'Renegade X MR', 'ATV'],
            ['Polaris', 'Sportsman 90', 'ATV'],
            ['Polaris', 'RZR Pro R 4', 'UTV'],
            ['Polaris', 'Sportsman 110', 'ATV'],
            ['Can-Am', 'Renegade 70', 'ATV'],
            ['Can-Am', 'Renegade 110', 'ATV'],
            ['Can-Am', 'Renegade X XC 110', 'ATV'],
            ['Yamaha', 'Raptor 110', 'ATV'],
            ['Yamaha', 'YFZ 50', 'ATV'],
            ['Polaris', 'XPedition', 'UTV'],
            ['Can-Am', 'Maverick R', 'UTV'],
            ['Kawasaki', 'Teryx KRX4 1000', 'UTV'],
            ['Polaris', 'XPedition 5', 'UTV'],
            ['Polaris', 'RZR Pro S', 'UTV'],
            ['Polaris', 'RZR Pro S 4', 'UTV'],
            ['Polaris', 'RZR Turbo R 4', 'UTV'],
            ['Polaris', 'RZR Pro XP 4', 'UTV'],
            ['Honda', 'Rubicon 700', 'ATV'],
            ['Kawasaki', 'Teryx H2', 'UTV'],
            ['Yamaha', 'Wolverine RMAX', 'UTV'],
            ['Polaris', 'RZR XP S 1000', 'UTV'],
            ['Polaris', 'RZR XP S 4 1000', 'UTV'],
            ['Can-Am', 'Maverick R MAX', 'UTV'],
        ];
        // Load existing manufacturer+model pairs so re-runs never duplicate.
        $vExisting = [];
        foreach ($pdo->query("SELECT manufacturer, model FROM vehicles")->fetchAll() as $vr) {
            $vExisting[strtolower($vr['manufacturer'] . '||' . $vr['model'])] = true;
        }
        $vIns = $pdo->prepare("
            INSERT INTO vehicles (manufacturer, model, model_year, vehicle_type)
            VALUES (?, ?, NULL, ?)
        ");
        $vSeeded = 0;
        foreach ($seedVehicles as $sv) {
            $vKey = strtolower($sv[0] . '||' . $sv[1]);
            if (!isset($vExisting[$vKey])) {
                $vIns->execute([$sv[0], $sv[1], $sv[2]]);
                $vExisting[$vKey] = true;
                $vSeeded++;
            }
        }
        if ($vSeeded > 0) {
            $steps[] = "✓ Seeded {$vSeeded} vehicle" . ($vSeeded === 1 ? '' : 's') . " from the catalog.";
        } else {
            $steps[] = "• Catalog vehicles already present — skipped.";
        }
    }

    // 15i. Extend prompts.category with the optional categories: 'references'
    //      (rule call-outs the AI must follow), 'custom' (catch-all), and
    //      'client' (client-specific prompts). Idempotent — inspects the ENUM
    //      and checks the most recent value, so re-runs after an earlier
    //      migration still add what's missing. Fresh installs already include
    //      them all via the CREATE TABLE above.
    if (tableExists($pdo, 'prompts')) {
        $catType = $pdo->query("
            SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'prompts' AND COLUMN_NAME = 'category'
        ")->fetchColumn();
        if ($catType && strpos($catType, "'client'") === false) {
            $pdo->exec("
                ALTER TABLE prompts
                MODIFY COLUMN category
                    ENUM('camera','lighting','environment','product','character','references','custom','client')
                    NOT NULL
            ");
            $steps[] = "✓ Extended prompts.category (references, custom, client).";
        } else {
            $steps[] = "• prompts.category already extended — skipped.";
        }
    }

    // 15j. Seed operator-supplied library prompts — reference rule blocks plus
    //      a white-studio environment and a camera spec. Runs after 15i so the
    //      'references' category exists. Idempotent: keyed on name.
    if (tableExists($pdo, 'prompts')) {
        $morePrompts = [
            [
                'category' => 'references',
                'name'     => 'Exhaust Image Reference',
                'prompt_text' => 'Use the reference image of the exhaust system as the absolute source of truth. Do not change the geometry, physical characteristics, or proportions of the exhaust. (critical) TEXT INTEGRITY - Keep all Text, logos, and marks identical. Do not imagine or change.',
            ],
            [
                'category' => 'references',
                'name'     => 'Vehicle Image Reference',
                'prompt_text' => 'Use the reference image of the vehicle as the absolute source of truth. Do not change the geometry, physical characteristics, or proportions of the vehicle. (critical) TEXT INTEGRITY - Keep all Text, logos, and marks identical. Do not imagine or change. Focus on the product on the vehicle. Focus and ensure it\'s clear and perfect.',
            ],
            [
                'category' => 'references',
                'name'     => 'Tire Image Reference',
                'prompt_text' => 'Use the reference image of the vehicle as the absolute source of truth. Do not change the geometry, physical characteristics, or proportions of the vehicle. (critical) Reproduce tire and wheel exactly. Do not modify geometry, proportions, tread, sidewall profile, or detail. TREAD (critical): Every tread must be pixel-faithful to Image 1. (Focus) Ensure no additional treads added (Critical) TEXT INTEGRITY (critical): Every sidewall character must be pixel-faithful to Image 2. Do not invent, smooth, stylize, or complete text. Treat sidewall text as fixed graphics copied directly from Image 2. Tread must match Image 1 exactly.',
            ],
            [
                'category' => 'environment',
                'name'     => 'White Studio Background',
                'prompt_text' => 'Seamless pure white',
            ],
            [
                'category' => 'camera',
                'name'     => '85–135mm',
                'prompt_text' => '85–135mm full-frame equivalent, f/8, deep DOF, tack sharp front to back. No wide-angle distortion.',
            ],
        ];
        $mpCheck = $pdo->prepare("SELECT COUNT(*) FROM prompts WHERE name = ?");
        $mpIns   = $pdo->prepare("
            INSERT INTO prompts (category, name, prompt_text, tags, compatible_models)
            VALUES (?, ?, ?, NULL, NULL)
        ");
        $mpSeeded = 0;
        foreach ($morePrompts as $mp) {
            $mpCheck->execute([$mp['name']]);
            if ((int)$mpCheck->fetchColumn() === 0) {
                $mpIns->execute([$mp['category'], $mp['name'], $mp['prompt_text']]);
                $mpSeeded++;
            }
        }
        if ($mpSeeded > 0) {
            $steps[] = "✓ Seeded {$mpSeeded} library prompt" . ($mpSeeded === 1 ? '' : 's') . ".";
        } else {
            $steps[] = "• Operator library prompts already present — skipped.";
        }
    }

    // 16. post_images.media_type — 'image' (default) or 'video'
    if (!columnExists($pdo, 'post_images', 'media_type')) {
        $pdo->exec("
            ALTER TABLE post_images
            ADD COLUMN media_type ENUM('image','video') NOT NULL DEFAULT 'image'
                AFTER image_url
        ");
        // Backfill: anything ending in a known video extension becomes 'video'
        $pdo->exec("
            UPDATE post_images
            SET media_type = 'video'
            WHERE LOWER(SUBSTRING_INDEX(image_url, '.', -1)) IN ('mp4','webm','mov','m4v')
        ");
        $steps[] = "✓ Added post_images.media_type (image/video).";
    } else {
        $steps[] = "• post_images.media_type already exists — skipped.";
    }

    // 17. Backfill synthetic comment events (run once, only if activity_log is empty)
    //     Builds the timestamp expression from whichever of updated_at / created_at
    //     actually exist on each table — older deployments may have only one of them.
    $bestTimestamp = function ($table) use ($pdo) {
        $cols = [];
        if (columnExists($pdo, $table, 'updated_at')) $cols[] = 'updated_at';
        if (columnExists($pdo, $table, 'created_at')) $cols[] = 'created_at';
        return $cols ? 'COALESCE(' . implode(', ', $cols) . ', NOW())' : 'NOW()';
    };

    if (tableExists($pdo, 'activity_log')) {
        $count = (int)$pdo->query("SELECT COUNT(*) FROM activity_log")->fetchColumn();
        if ($count === 0) {
            $inserted = 0;
            // Posts with comments
            if (tableExists($pdo, 'posts')) {
                $tsExpr = $bestTimestamp('posts');
                $rows = $pdo->query("
                    SELECT id, company_id, client_comment, {$tsExpr} AS at
                    FROM posts
                    WHERE client_comment IS NOT NULL AND client_comment <> ''
                ")->fetchAll();
                $stmt = $pdo->prepare("
                    INSERT INTO activity_log
                        (company_id, entity_type, entity_id, action, actor,
                         summary, detail, digest_id, created_at)
                    VALUES (?, 'post', ?, 'commented', 'unknown', ?, ?, 0, ?)
                ");
                foreach ($rows as $r) {
                    $summary = "Comment on post #{$r['id']} (backfilled)";
                    $stmt->execute([$r['company_id'], $r['id'], $summary, $r['client_comment'], $r['at']]);
                    $inserted++;
                }
            }
            // Tire images with comments — need company_id from tires join
            if (tableExists($pdo, 'tire_images')) {
                $tsExpr = str_replace('updated_at', 'ti.updated_at',
                          str_replace('created_at', 'ti.created_at',
                                      $bestTimestamp('tire_images')));
                $rows = $pdo->query("
                    SELECT ti.id, t.company_id, ti.client_comment, {$tsExpr} AS at
                    FROM tire_images ti
                    INNER JOIN tires t ON t.id = ti.tire_id
                    WHERE ti.client_comment IS NOT NULL AND ti.client_comment <> ''
                ")->fetchAll();
                $stmt = $pdo->prepare("
                    INSERT INTO activity_log
                        (company_id, entity_type, entity_id, action, actor,
                         summary, detail, digest_id, created_at)
                    VALUES (?, 'tire_image', ?, 'commented', 'unknown', ?, ?, 0, ?)
                ");
                foreach ($rows as $r) {
                    $summary = "Comment on image #{$r['id']} (backfilled)";
                    $stmt->execute([$r['company_id'], $r['id'], $summary, $r['client_comment'], $r['at']]);
                    $inserted++;
                }
            }
            $steps[] = "✓ Backfilled {$inserted} synthetic comment event"
                     . ($inserted === 1 ? '' : 's') . ".";
        } else {
            $steps[] = "• activity_log already populated ({$count} rows) — skipped backfill.";
        }
    }

    // 18. library_images — one row per file dropped into a brand's
    //     media/library/{slug}/ folder. Populated on-the-fly by library.php
    //     as it scans the folder; this table just remembers each file's
    //     approve/deny decision across visits.
    if (!tableExists($pdo, 'library_images')) {
        $pdo->exec("
            CREATE TABLE library_images (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                company_id INT UNSIGNED NOT NULL,
                filename VARCHAR(255) NOT NULL,
                status ENUM('pending','approved','denied') NOT NULL DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_library_company_filename (company_id, filename),
                KEY idx_library_images_company (company_id),
                CONSTRAINT fk_library_images_company FOREIGN KEY (company_id)
                    REFERENCES companies(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $steps[] = "✓ Created `library_images` table.";
    } else {
        $steps[] = "• `library_images` table already exists — skipped.";
    }
} catch (Exception $e) {
    $errors[] = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Migrate</title>
<style>
  body { font: 15px/1.5 -apple-system, BlinkMacSystemFont, sans-serif;
         background: #f0f2f5; color: #050505; padding: 40px; max-width: 680px; margin: 0 auto; }
  h1 { margin-top: 0; }
  .box { background: #fff; border: 1px solid #dadde1; border-radius: 12px;
         padding: 20px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
  .ok  { color: #166534; }
  .skip{ color: #65676b; }
  .err { color: #991b1b; background: #fee2e2; border: 1px solid #fca5a5;
         padding: 10px; border-radius: 8px; margin-bottom: 10px; }
  a.btn { display: inline-block; padding: 10px 18px; border-radius: 8px;
          background: #1877f2; color: #fff; text-decoration: none; font-weight: 600; }
  ul { padding-left: 20px; }
  li { margin: 6px 0; }
  code { background: #f0f2f5; padding: 2px 6px; border-radius: 4px; font-size: 13px; }
</style>
</head>
<body>
<h1>Database migration</h1>

<div class="box">
  <strong>Results:</strong>
  <ul>
    <?php foreach ($steps as $s): ?>
      <li class="<?= strpos($s, '✓') === 0 ? 'ok' : 'skip' ?>"><?= htmlspecialchars($s) ?></li>
    <?php endforeach; ?>
  </ul>
  <?php if ($errors): ?>
    <?php foreach ($errors as $err): ?>
      <div class="err">⚠ <?= htmlspecialchars($err) ?></div>
    <?php endforeach; ?>
  <?php else: ?>
    <p>
      Migration complete. If your existing tires should belong to a different company, reassign
      them in <code>phpMyAdmin</code> by updating <code>tires.company_id</code>, or add more
      rows to <code>company_modules</code> to enable the tires module on other clients.
    </p>
    <p>Re-running this page is safe — every step checks first.</p>
  <?php endif; ?>
</div>

<a class="btn" href="admin.php">→ Go to admin</a>
</body>
</html>
