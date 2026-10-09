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

    // 19. emails — external HTML emails reviewed per client (Emails tab).
    //     One row per email: code ('C1', 'R3'), title, link to the hosted HTML,
    //     subject / preview / trigger copy, optional send date, priority,
    //     review status (draft → pending → approved | denied) and a `live`
    //     flag that wins over status for display. See emails-lib.php.
    if (!tableExists($pdo, 'emails')) {
        $pdo->exec("
            CREATE TABLE emails (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                company_id INT UNSIGNED NOT NULL,
                code VARCHAR(32) NOT NULL,
                title VARCHAR(255) NOT NULL DEFAULT '',
                html_url VARCHAR(512) NOT NULL DEFAULT '',
                subject VARCHAR(255) NOT NULL DEFAULT '',
                preview_text TEXT NULL,
                trigger_text TEXT NULL,
                send_at DATE NULL,
                priority ENUM('low','medium','high') NULL,
                status ENUM('draft','pending','approved','denied') NOT NULL DEFAULT 'draft',
                live TINYINT(1) NOT NULL DEFAULT 0,
                live_at DATETIME NULL,
                sort_order INT NOT NULL DEFAULT 0,
                notes TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_company_code (company_id, code),
                KEY ix_company_status (company_id, status, live)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $steps[] = "✓ Created `emails` table.";
    } else {
        $steps[] = "• `emails` table already exists — skipped.";
    }

    // 20. email_groups — per-client tags (Free, Pro, Leads, Renewal, System …).
    if (!tableExists($pdo, 'email_groups')) {
        $pdo->exec("
            CREATE TABLE email_groups (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                company_id INT UNSIGNED NOT NULL,
                name VARCHAR(80) NOT NULL,
                slug VARCHAR(80) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                UNIQUE KEY uq_company_slug (company_id, slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $steps[] = "✓ Created `email_groups` table.";
    } else {
        $steps[] = "• `email_groups` table already exists — skipped.";
    }

    // 21. email_group_map — many-to-many emails ↔ groups.
    if (!tableExists($pdo, 'email_group_map')) {
        $pdo->exec("
            CREATE TABLE email_group_map (
                email_id INT UNSIGNED NOT NULL,
                group_id INT UNSIGNED NOT NULL,
                PRIMARY KEY (email_id, group_id),
                KEY ix_email_group_map_group (group_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $steps[] = "✓ Created `email_group_map` table.";
    } else {
        $steps[] = "• `email_group_map` table already exists — skipped.";
    }

    // 22. 'emails' module row — enable the Emails tab per client with one
    //     company_modules (company_id, module_id) row. (The tab also appears
    //     automatically once a client has any emails row.)
    $s = $pdo->prepare("SELECT id FROM modules WHERE slug = 'emails'");
    $s->execute();
    $emailsModuleId = (int)$s->fetchColumn();
    if (!$emailsModuleId) {
        $pdo->prepare("
            INSERT INTO modules (slug, singular_label, plural_label, icon)
            VALUES ('emails', 'Email', 'Emails', '✉️')
        ")->execute();
        $emailsModuleId = (int)$pdo->lastInsertId();
        $steps[] = "✓ Seeded 'emails' module (id={$emailsModuleId}) — add a company_modules row per client to enable the Emails tab.";
    } else {
        $steps[] = "• 'emails' module already seeded (id={$emailsModuleId}).";
    }

    // 23. email_flows — a named, ordered sequence of a client's emails
    //     ("Free" = F1 → F4 → C2 …). Admin builds them (flow-status.php),
    //     clients view them (flows.php). See flows-lib.php.
    if (!tableExists($pdo, 'email_flows')) {
        $pdo->exec("
            CREATE TABLE email_flows (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                company_id INT UNSIGNED NOT NULL,
                name VARCHAR(120) NOT NULL,
                slug VARCHAR(120) NOT NULL,
                description TEXT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_company_slug (company_id, slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $steps[] = "✓ Created `email_flows` table.";
    } else {
        $steps[] = "• `email_flows` table already exists — skipped.";
    }

    // 24. email_flow_steps — the emails inside a flow, in order, with the
    //     timing between steps ("3 days after F1") and an optional note.
    if (!tableExists($pdo, 'email_flow_steps')) {
        $pdo->exec("
            CREATE TABLE email_flow_steps (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                flow_id INT UNSIGNED NOT NULL,
                email_id INT UNSIGNED NOT NULL,
                position INT NOT NULL DEFAULT 0,
                timing_text VARCHAR(255) NULL,
                note TEXT NULL,
                UNIQUE KEY uq_flow_email (flow_id, email_id),
                KEY ix_flow_pos (flow_id, position)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $steps[] = "✓ Created `email_flow_steps` table.";
    } else {
        $steps[] = "• `email_flow_steps` table already exists — skipped.";
    }

    // 25. tire_series — one row per render folder under media/tires/<tire-slug>/<folder>/
    //     (see tire-series-lib.php). Scanned from disk or created by tire-upload.php.
    if (!tableExists($pdo, 'tire_series')) {
        $pdo->exec("
            CREATE TABLE tire_series (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tire_id INT UNSIGNED NOT NULL,
                name VARCHAR(120) NOT NULL,
                slug VARCHAR(120) NOT NULL,
                folder VARCHAR(255) NULL DEFAULT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_tire_slug (tire_id, slug),
                KEY ix_tire (tire_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $steps[] = "✓ Created `tire_series` table.";
    } else {
        $steps[] = "• `tire_series` table already exists — skipped.";
    }

    // 26. tire_images.series_id — NULL = a reference image (the admin's ≤6 uploads),
    //     otherwise the series the render belongs to. The only change to an existing table.
    if (tableExists($pdo, 'tire_images')) {
        if (!columnExists($pdo, 'tire_images', 'series_id')) {
            $pdo->exec("
                ALTER TABLE tire_images
                ADD COLUMN series_id INT UNSIGNED NULL DEFAULT NULL AFTER tire_id,
                ADD KEY ix_series (series_id)
            ");
            $steps[] = "✓ Added tire_images.series_id.";
        } else {
            $steps[] = "• tire_images.series_id already exists — skipped.";
        }
    }

    // 27. pages — static HTML landing pages reviewed per client (Pages tab).
    //     One row per page: a folder media/pages/<client-slug>/<page-slug>/
    //     holding index.html + assets (source = 'upload') or an external URL
    //     (source = 'url'); review status + live flag like emails. See pages-lib.php.
    if (!tableExists($pdo, 'pages')) {
        $pdo->exec("
            CREATE TABLE pages (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                company_id INT UNSIGNED NOT NULL,
                title VARCHAR(160) NOT NULL DEFAULT '',
                slug VARCHAR(120) NOT NULL,
                source ENUM('upload','url') NOT NULL DEFAULT 'upload',
                url VARCHAR(512) NULL,
                entry VARCHAR(255) NOT NULL DEFAULT 'index.html',
                description TEXT NULL,
                status ENUM('draft','pending','approved','denied') NOT NULL DEFAULT 'draft',
                live TINYINT(1) NOT NULL DEFAULT 0,
                live_at DATETIME NULL,
                sort_order INT NOT NULL DEFAULT 0,
                notes TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_company_slug (company_id, slug),
                KEY ix_company_status (company_id, status, live)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $steps[] = "✓ Created `pages` table.";
    } else {
        $steps[] = "• `pages` table already exists — skipped.";
    }

    // 28. page_files — the files uploaded into a page's folder (relative path
    //     inside the folder, e.g. 'index.html', 'css/style.css') so the portal
    //     can list them, pick the entry file and clean up on delete.
    if (!tableExists($pdo, 'page_files')) {
        $pdo->exec("
            CREATE TABLE page_files (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                page_id INT UNSIGNED NOT NULL,
                filename VARCHAR(255) NOT NULL,
                size INT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_page_file (page_id, filename),
                KEY ix_page_files_page (page_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $steps[] = "✓ Created `page_files` table.";
    } else {
        $steps[] = "• `page_files` table already exists — skipped.";
    }

    // 28b. 'pages' module row — enable the Pages tab per client with one
    //      company_modules (company_id, module_id) row (Studio → Pages toggles it).
    //      The tab also appears automatically once a client has any pages row.
    $s = $pdo->prepare("SELECT id FROM modules WHERE slug = 'pages'");
    $s->execute();
    $pagesModuleId = (int)$s->fetchColumn();
    if (!$pagesModuleId) {
        $pdo->prepare("
            INSERT INTO modules (slug, singular_label, plural_label, icon)
            VALUES ('pages', 'Page', 'Pages', '📄')
        ")->execute();
        $pagesModuleId = (int)$pdo->lastInsertId();
        $steps[] = "✓ Seeded 'pages' module (id={$pagesModuleId}) — add a company_modules row per client to enable the Pages tab.";
    } else {
        $steps[] = "• 'pages' module already seeded (id={$pagesModuleId}).";
    }

    // 29. tire_series.drive_url — an optional Google Drive share link per series: the client
    //     gets an "Open in Google Drive" button on the series (Assets → Collections) as an
    //     alternative way to browse the renders. Additive on the table step 25 created;
    //     tireSeriesHasDriveUrl() (tire-series-lib.php) gates every read/write until this runs.
    if (tableExists($pdo, 'tire_series')) {
        if (!columnExists($pdo, 'tire_series', 'drive_url')) {
            $pdo->exec("ALTER TABLE tire_series ADD COLUMN drive_url VARCHAR(512) NULL DEFAULT NULL AFTER folder");
            $steps[] = "✓ Added tire_series.drive_url (Google Drive link per series).";
        } else {
            $steps[] = "• tire_series.drive_url already exists — skipped.";
        }
    }

    // 30–34. Google Drive storage view (drive.php + drive-ingest.php; helpers in drive-lib.php).
    //     A nightly Apps Script (docs/drive-collector/) posts one snapshot of the agency Drive in
    //     parts; the portal only ever reads these tables. drive_snapshots rows are kept forever
    //     (the usage history); folder / file / quick-win detail is pruned to the newest 7 complete
    //     snapshots by the ingest endpoint. hasDriveTables() gates every read until this has run.
    // 30. drive_snapshots — one row per collector run (quota numbers + the JSON rollups)
    if (!tableExists($pdo, 'drive_snapshots')) {
        $pdo->exec("
            CREATE TABLE drive_snapshots (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                taken_at DATETIME NOT NULL,
                status ENUM('partial','complete','failed') NOT NULL DEFAULT 'partial',
                quota_limit BIGINT UNSIGNED NULL DEFAULT NULL,
                usage_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
                drive_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
                trash_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
                other_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
                quota_note VARCHAR(255) NULL DEFAULT NULL,
                account_email VARCHAR(255) NULL DEFAULT NULL,
                script_version VARCHAR(40) NULL DEFAULT NULL,
                file_count INT UNSIGNED NOT NULL DEFAULT 0,
                folder_count INT UNSIGNED NOT NULL DEFAULT 0,
                candidate_count INT UNSIGNED NOT NULL DEFAULT 0,
                listed_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
                burn_rate_per_day BIGINT NULL DEFAULT NULL,
                days_to_full INT UNSIGNED NULL DEFAULT NULL,
                basis_days SMALLINT UNSIGNED NULL DEFAULT NULL,
                clients_json MEDIUMTEXT NULL,
                tree_json MEDIUMTEXT NULL,
                by_type_json TEXT NULL,
                quick_wins_json TEXT NULL,
                state_json MEDIUMTEXT NULL,
                part_hashes TEXT NULL,
                duration_ms INT UNSIGNED NULL DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                finished_at DATETIME NULL DEFAULT NULL,
                KEY ix_taken (taken_at),
                KEY ix_status_taken (status, taken_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $steps[] = "✓ Created `drive_snapshots` table.";
    } else {
        $steps[] = "• `drive_snapshots` already exists — skipped.";
    }

    // 31. drive_folders — every owned folder with the collector's rollups (bytes, stale bytes, …)
    if (!tableExists($pdo, 'drive_folders')) {
        $pdo->exec("
            CREATE TABLE drive_folders (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                snapshot_id INT UNSIGNED NOT NULL,
                folder_id VARCHAR(64) NOT NULL,
                parent_id VARCHAR(64) NULL DEFAULT NULL,
                name VARCHAR(255) NOT NULL DEFAULT '',
                path VARCHAR(1024) NOT NULL DEFAULT '',
                depth SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                client_slug VARCHAR(120) NULL DEFAULT NULL,
                bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
                stale_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
                file_count INT UNSIGNED NOT NULL DEFAULT 0,
                last_activity_at DATETIME NULL DEFAULT NULL,
                web_link VARCHAR(255) NULL DEFAULT NULL,
                UNIQUE KEY uq_snapshot_folder (snapshot_id, folder_id),
                KEY ix_snapshot_client (snapshot_id, client_slug),
                KEY ix_snapshot_parent (snapshot_id, parent_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $steps[] = "✓ Created `drive_folders` table.";
    } else {
        $steps[] = "• `drive_folders` already exists — skipped.";
    }

    // 32. drive_files — the files worth keeping per snapshot (offboard candidates + the largest)
    if (!tableExists($pdo, 'drive_files')) {
        $pdo->exec("
            CREATE TABLE drive_files (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                snapshot_id INT UNSIGNED NOT NULL,
                file_id VARCHAR(64) NOT NULL,
                name VARCHAR(255) NOT NULL DEFAULT '',
                mime_type VARCHAR(120) NOT NULL DEFAULT '',
                path VARCHAR(1024) NULL DEFAULT NULL,
                parent_id VARCHAR(64) NULL DEFAULT NULL,
                client_slug VARCHAR(120) NULL DEFAULT NULL,
                type_bucket VARCHAR(16) NOT NULL DEFAULT 'Other',
                bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
                modified_at DATETIME NULL DEFAULT NULL,
                viewed_at DATETIME NULL DEFAULT NULL,
                created_at_drive DATETIME NULL DEFAULT NULL,
                idle_days INT UNSIGNED NOT NULL DEFAULT 0,
                raw_score BIGINT UNSIGNED NOT NULL DEFAULT 0,
                score TINYINT UNSIGNED NOT NULL DEFAULT 0,
                is_candidate TINYINT(1) NOT NULL DEFAULT 0,
                md5 CHAR(32) NULL DEFAULT NULL,
                web_link VARCHAR(255) NULL DEFAULT NULL,
                UNIQUE KEY uq_snapshot_file (snapshot_id, file_id),
                KEY ix_snapshot_client (snapshot_id, client_slug),
                KEY ix_snapshot_candidate (snapshot_id, is_candidate, score),
                KEY ix_snapshot_bytes (snapshot_id, bytes)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $steps[] = "✓ Created `drive_files` table.";
    } else {
        $steps[] = "• `drive_files` already exists — skipped.";
    }

    // 33. drive_quick_wins — duplicate / old-version groups the server found in a snapshot
    if (!tableExists($pdo, 'drive_quick_wins')) {
        $pdo->exec("
            CREATE TABLE drive_quick_wins (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                snapshot_id INT UNSIGNED NOT NULL,
                kind ENUM('duplicate','old_version') NOT NULL,
                group_key VARCHAR(80) NOT NULL DEFAULT '',
                name VARCHAR(255) NOT NULL DEFAULT '',
                path VARCHAR(1024) NULL DEFAULT NULL,
                client_slug VARCHAR(120) NULL DEFAULT NULL,
                file_count INT UNSIGNED NOT NULL DEFAULT 0,
                bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
                reclaimable_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
                files_json TEXT NULL,
                KEY ix_snapshot_kind (snapshot_id, kind, reclaimable_bytes)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $steps[] = "✓ Created `drive_quick_wins` table.";
    } else {
        $steps[] = "• `drive_quick_wins` already exists — skipped.";
    }

    // 34. drive_alerts — one row per threshold email actually sent (80 / 90 / 95 %, < 14 days to full),
    //     so a crossing is mailed once even when the collector re-runs finish.
    if (!tableExists($pdo, 'drive_alerts')) {
        $pdo->exec("
            CREATE TABLE drive_alerts (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                snapshot_id INT UNSIGNED NOT NULL,
                kind ENUM('pct80','pct90','pct95','days14') NOT NULL,
                sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_snapshot_kind (snapshot_id, kind)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $steps[] = "✓ Created `drive_alerts` table.";
    } else {
        $steps[] = "• `drive_alerts` already exists — skipped.";
    }

    // 35. posts.status 'draft' — a post Joust is still building: never shown to the client (lists,
    //     counts, badges, Home, activity, deep links) until "Send for review" (status.php
    //     action=submit → pending). Studio uploads / batch files land here instead of To Review.
    //     Gated on the live column type, so re-running is a no-op; postsHaveDraft() (helpers.php)
    //     keeps every page working before this has run.
    $s = $pdo->prepare("
        SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'status'
    ");
    $s->execute();
    $postStatusType = strtolower((string)$s->fetchColumn());
    if ($postStatusType !== '' && strpos($postStatusType, "'draft'") === false) {
        $pdo->exec("ALTER TABLE posts MODIFY status ENUM('draft','pending','approved','denied') NOT NULL DEFAULT 'pending'");
        $steps[] = "✓ Added the Draft status to posts.status.";
    } else {
        $steps[] = "• posts.status already has Draft — skipped.";
    }

    // 36–39. Notifications (notify-lib.php; Manage → Notifications). notifyReady() (notify-lib.php) gates every
    //     read / write until these have run, so the portal keeps working on a deploy that has not migrated yet.
    // 36. admin_users — named Joust identities (who wrote a comment, who gets the Slack @mention / escalation
    //     email). Lance is seeded from the single login in auth.php (ADMIN_EMAIL), which keeps working unchanged:
    //     currentAdminUserId() maps the signed-in email to a row. password_hash is reserved for per-person logins.
    if (!tableExists($pdo, 'admin_users')) {
        $pdo->exec("
            CREATE TABLE admin_users (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(80) NOT NULL,
                email VARCHAR(190) NOT NULL,
                slack_user_id VARCHAR(32) NULL DEFAULT NULL,
                password_hash VARCHAR(255) NULL DEFAULT NULL,
                role VARCHAR(20) NOT NULL DEFAULT 'admin',
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_email (email),
                KEY ix_slack (slack_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $steps[] = "✓ Created `admin_users` table.";
    } else {
        $steps[] = "• `admin_users` already exists — skipped.";
    }
    $seedEmail = defined('ADMIN_EMAIL') ? (string)ADMIN_EMAIL : '';
    if ($seedEmail !== '' && (int)$pdo->query("SELECT COUNT(*) FROM admin_users")->fetchColumn() === 0) {
        $pdo->prepare("INSERT INTO admin_users (name, email, role) VALUES ('Lance', ?, 'owner')")->execute([$seedEmail]);
        $steps[] = "✓ Seeded admin user Lance ({$seedEmail}) from the current login.";
    } else {
        $steps[] = "• admin_users already has people — seed skipped.";
    }

    // 37. activity_log.author_user_id (which admin_users row wrote it; NULL = the client seat / legacy rows) and
    //     activity_log.internal (1 = a Joust-only note: hidden from the client seat in every surface — threads,
    //     feeds, counts, Home — and never in the Morning summary or a client email). Plus the notification
    //     settings in `meta` (escalation thresholds in minutes, Morning summary hour) and notify_since, the floor
    //     below which old client messages never escalate (so the first deploy does not page about history).
    $alAdd = [];
    if (!columnExists($pdo, 'activity_log', 'author_user_id')) $alAdd[] = "ADD COLUMN author_user_id INT UNSIGNED NULL DEFAULT NULL AFTER actor";
    if (!columnExists($pdo, 'activity_log', 'internal'))       $alAdd[] = "ADD COLUMN internal TINYINT(1) NOT NULL DEFAULT 0 AFTER author_user_id";
    if ($alAdd) {
        $pdo->exec("ALTER TABLE activity_log " . implode(', ', $alAdd));
        $steps[] = "✓ Added activity_log author / internal columns.";
    } else {
        $steps[] = "• activity_log.author_user_id / internal already exist — skipped.";
    }
    $metaIns = $pdo->prepare("INSERT IGNORE INTO meta (k, v) VALUES (?, ?)");
    $metaNew = 0;
    foreach ([
        ['notify_since', date('Y-m-d H:i:s')],
        ['notify_t1_minutes', '60'],
        ['notify_t2_minutes', '240'],
        ['notify_summary_hour', '8'],
        ['notify_summary_last', '1970-01-01'],
    ] as $kv) {
        $metaIns->execute($kv);
        $metaNew += $metaIns->rowCount();
    }
    $steps[] = $metaNew > 0 ? "✓ Seeded {$metaNew} notification settings." : "• Notification settings already seeded — skipped.";

    // 38. notify_outbox — every outbound Slack / email message (delivery log + retry with backoff; dedupe_key makes
    //     enqueueing idempotent) — and notify_clients — per client: its Slack channel and the Joust owner to @mention.
    if (!tableExists($pdo, 'notify_outbox')) {
        $pdo->exec("
            CREATE TABLE notify_outbox (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                channel ENUM('slack','email') NOT NULL,
                kind VARCHAR(30) NOT NULL,
                company_id INT UNSIGNED NULL DEFAULT NULL,
                entity_type VARCHAR(20) NULL DEFAULT NULL,
                entity_id INT UNSIGNED NULL DEFAULT NULL,
                target VARCHAR(190) NULL DEFAULT NULL,
                payload MEDIUMTEXT NOT NULL,
                status ENUM('pending','sending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
                attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_error VARCHAR(500) NULL DEFAULT NULL,
                dedupe_key VARCHAR(120) NULL DEFAULT NULL,
                provider_id VARCHAR(190) NULL DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                sent_at DATETIME NULL DEFAULT NULL,
                UNIQUE KEY uq_dedupe (dedupe_key),
                KEY ix_due (status, next_attempt_at),
                KEY ix_entity (entity_type, entity_id),
                KEY ix_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $steps[] = "✓ Created `notify_outbox` table.";
    } else {
        $steps[] = "• `notify_outbox` already exists — skipped.";
    }
    if (!tableExists($pdo, 'notify_clients')) {
        $pdo->exec("
            CREATE TABLE notify_clients (
                company_id INT UNSIGNED NOT NULL PRIMARY KEY,
                slack_channel_id VARCHAR(32) NULL DEFAULT NULL,
                slack_channel_name VARCHAR(80) NULL DEFAULT NULL,
                owner_user_id INT UNSIGNED NULL DEFAULT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $steps[] = "✓ Created `notify_clients` table.";
    } else {
        $steps[] = "• `notify_clients` already exists — skipped.";
    }

    // 39. notify_threads — one row per portal item that has a conversation outside the portal: its Slack parent
    //     message (channel + ts, the parent's last rendered hash) and the email Message-ID later mails thread on
    //     (In-Reply-To / References). slack_inbox — every verified Slack delivery by event_id (dedupe of Slack's
    //     retries) with what the portal did with it.
    if (!tableExists($pdo, 'notify_threads')) {
        $pdo->exec("
            CREATE TABLE notify_threads (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                company_id INT UNSIGNED NOT NULL,
                entity_type VARCHAR(20) NOT NULL,
                entity_id INT UNSIGNED NOT NULL,
                slack_channel VARCHAR(32) NULL DEFAULT NULL,
                slack_ts VARCHAR(32) NULL DEFAULT NULL,
                slack_claimed_at DATETIME NULL DEFAULT NULL,
                parent_hash CHAR(40) NULL DEFAULT NULL,
                email_message_id VARCHAR(190) NULL DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_entity (entity_type, entity_id),
                KEY ix_slack (slack_channel, slack_ts),
                KEY ix_company (company_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $steps[] = "✓ Created `notify_threads` table.";
    } else {
        $steps[] = "• `notify_threads` already exists — skipped.";
    }
    if (!tableExists($pdo, 'slack_inbox')) {
        $pdo->exec("
            CREATE TABLE slack_inbox (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                event_id VARCHAR(80) NOT NULL,
                kind VARCHAR(30) NOT NULL DEFAULT '',
                channel VARCHAR(32) NULL DEFAULT NULL,
                user_id VARCHAR(32) NULL DEFAULT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'received',
                note VARCHAR(255) NULL DEFAULT NULL,
                activity_id INT UNSIGNED NULL DEFAULT NULL,
                received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                processed_at DATETIME NULL DEFAULT NULL,
                UNIQUE KEY uq_event (event_id),
                KEY ix_received (received_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $steps[] = "✓ Created `slack_inbox` table.";
    } else {
        $steps[] = "• `slack_inbox` already exists — skipped.";
    }
} catch (Exception $e) {
    $errors[] = $e->getMessage();
}

// 40–43. Client sign-in (client-auth-lib.php): per-client contact emails, one-time magic links, 30-day sessions,
//        and the rate-limit ledger. A separate block so steps 36–39 (notifications) can sit above it untouched.
if (!$errors) {
    try {
        // 40. client_contacts — the per-client list of addresses that may sign in (Manage → Clients → Contacts).
        //     link_epoch: bumped by "Sign out everywhere" to void that contact's emailed deep links.
        if (!tableExists($pdo, 'client_contacts')) {
            $pdo->exec("
                CREATE TABLE client_contacts (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    company_id INT UNSIGNED NOT NULL,
                    email VARCHAR(190) NOT NULL,
                    name VARCHAR(120) NULL DEFAULT NULL,
                    link_epoch INT UNSIGNED NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    last_login_at DATETIME NULL DEFAULT NULL,
                    UNIQUE KEY uq_company_email (company_id, email),
                    KEY ix_email (email)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $steps[] = "✓ Created `client_contacts` table.";
        } else {
            $steps[] = "• `client_contacts` already exists — skipped.";
        }
        // 40b. Carry over any client email column an older install may have (companies.email / emails / contact_email /
        //      client_email / notify_email — comma, semicolon or space separated). INSERT IGNORE: re-runs add nothing.
        $legacyCols = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'companies' AND COLUMN_NAME IN ('email', 'emails', 'contact_email', 'client_email', 'notify_email')")->fetchAll(PDO::FETCH_COLUMN);
        $carried = 0;
        foreach ($legacyCols as $col) {
            $ins = $pdo->prepare("INSERT IGNORE INTO client_contacts (company_id, email) VALUES (?, ?)");
            foreach ($pdo->query("SELECT id, `{$col}` AS v FROM companies WHERE `{$col}` IS NOT NULL AND `{$col}` <> ''")->fetchAll() as $row) {
                foreach (preg_split('/[\s,;]+/', strtolower((string)$row['v'])) as $addr) {
                    if ($addr !== '' && strlen($addr) <= 190 && filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                        $ins->execute([(int)$row['id'], $addr]);
                        $carried += $ins->rowCount();
                    }
                }
            }
        }
        if ($legacyCols) {
            $steps[] = $carried > 0 ? "✓ Copied {$carried} client email address(es) into `client_contacts`." : "• Client email columns already copied — skipped.";
        }

        // 41. client_login_tokens — one-time sign-in links (sha256 of the token only), 15-minute expiry, single use.
        if (!tableExists($pdo, 'client_login_tokens')) {
            $pdo->exec("
                CREATE TABLE client_login_tokens (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    contact_id INT UNSIGNED NOT NULL,
                    token_hash CHAR(64) NOT NULL,
                    return_path VARCHAR(1000) NULL DEFAULT NULL,
                    ip VARCHAR(45) NULL DEFAULT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    expires_at DATETIME NOT NULL,
                    used_at DATETIME NULL DEFAULT NULL,
                    UNIQUE KEY uq_token (token_hash),
                    KEY ix_contact (contact_id),
                    KEY ix_expires (expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $steps[] = "✓ Created `client_login_tokens` table.";
        } else {
            $steps[] = "• `client_login_tokens` already exists — skipped.";
        }

        // 42. client_sessions — a signed-in browser (cookie jsm_client = random token, sha256 stored), 30 days,
        //     revocable from Manage → Clients.
        if (!tableExists($pdo, 'client_sessions')) {
            $pdo->exec("
                CREATE TABLE client_sessions (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    contact_id INT UNSIGNED NOT NULL,
                    company_id INT UNSIGNED NOT NULL,
                    token_hash CHAR(64) NOT NULL,
                    via VARCHAR(12) NOT NULL DEFAULT 'magic',
                    ip VARCHAR(45) NULL DEFAULT NULL,
                    user_agent VARCHAR(255) NULL DEFAULT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    last_seen_at DATETIME NULL DEFAULT NULL,
                    expires_at DATETIME NOT NULL,
                    revoked_at DATETIME NULL DEFAULT NULL,
                    revoked_by VARCHAR(20) NULL DEFAULT NULL,
                    UNIQUE KEY uq_token (token_hash),
                    KEY ix_company_live (company_id, revoked_at, expires_at),
                    KEY ix_contact (contact_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $steps[] = "✓ Created `client_sessions` table.";
        } else {
            $steps[] = "• `client_sessions` already exists — skipped.";
        }

        // 43. auth_attempts — sign-in request ledger for the per-address and per-IP rate limits (hashed keys).
        if (!tableExists($pdo, 'auth_attempts')) {
            $pdo->exec("
                CREATE TABLE auth_attempts (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    scope VARCHAR(10) NOT NULL,
                    key_hash CHAR(64) NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY ix_scope_key (scope, key_hash, created_at),
                    KEY ix_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $steps[] = "✓ Created `auth_attempts` table.";
        } else {
            $steps[] = "• `auth_attempts` already exists — skipped.";
        }
    } catch (Exception $e) {
        $errors[] = $e->getMessage();
    }
}

// 44. activity_log.client_contact_id — which signed-in client contact (client_contacts.id) wrote a client row, so
//     Slack and the admin views can say "Jane (Kenda Tires)" instead of just the client. NULL = unknown (rows from
//     before sign-in, the admin's "reply as client", a removed contact). Probed by activityHasContactCol()
//     (notify-lib.php); every reader works without it.
if (!$errors) {
    try {
        if (!columnExists($pdo, 'activity_log', 'client_contact_id')) {
            $pdo->exec("ALTER TABLE activity_log ADD COLUMN client_contact_id INT UNSIGNED NULL DEFAULT NULL");
            $steps[] = "✓ Added activity_log.client_contact_id (which client contact wrote it).";
        } else {
            $steps[] = "• activity_log.client_contact_id already exists — skipped.";
        }
    } catch (Exception $e) {
        $errors[] = $e->getMessage();
    }
}

// 45–49. Email through Google (gmail-lib.php), client notification emails (client-notify-lib.php) and tracking
//        (tracking-lib.php): Inbox, unread markers, weekly report. Every reader probes for its tables first.
if (!$errors) {
    try {
        // 45. google_account — the ONE connected Google Workspace mailbox (lance@joustmedia.com): the OAuth refresh
        //     token and the cached access token, both ENCRYPTED (gmail-lib.php googleEncrypt(): libsodium secretbox, or
        //     AES-256-GCM, key derived from config google_token_key); never stored or shown in clear. Plus the health
        //     line Manage → Notifications shows (last success / last error) and the "portal-processed" label id.
        if (!tableExists($pdo, 'google_account')) {
            $pdo->exec("
                CREATE TABLE google_account (
                    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
                    account_email VARCHAR(190) NOT NULL,
                    refresh_token_enc TEXT NOT NULL,
                    access_token_enc TEXT NULL DEFAULT NULL,
                    access_expires_at DATETIME NULL DEFAULT NULL,
                    scopes VARCHAR(500) NULL DEFAULT NULL,
                    label_id VARCHAR(64) NULL DEFAULT NULL,
                    connected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    connected_by VARCHAR(190) NULL DEFAULT NULL,
                    last_success_at DATETIME NULL DEFAULT NULL,
                    last_error VARCHAR(500) NULL DEFAULT NULL,
                    last_error_at DATETIME NULL DEFAULT NULL,
                    last_poll_at DATETIME NULL DEFAULT NULL,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $steps[] = "✓ Created `google_account` table.";
        } else {
            $steps[] = "• `google_account` already exists — skipped.";
        }

        // 46. Inbound email replies: email_inbound (every Gmail message the cron looked at, deduped by Gmail id, with
        //     what happened: posted / unmatched / dismissed / assigned / ignored), notify_email_refs (every Message-ID
        //     the portal sent to a client → its client, item and contact: replies are matched on In-Reply-To /
        //     References), notify_threads.email_token (the short signed [J#…] subject token per item, the fallback match).
        if (!tableExists($pdo, 'email_inbound')) {
            $pdo->exec("
                CREATE TABLE email_inbound (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    gmail_id VARCHAR(64) NOT NULL,
                    thread_id VARCHAR(64) NULL DEFAULT NULL,
                    message_id VARCHAR(190) NULL DEFAULT NULL,
                    from_email VARCHAR(190) NOT NULL DEFAULT '',
                    from_name VARCHAR(190) NULL DEFAULT NULL,
                    subject VARCHAR(500) NULL DEFAULT NULL,
                    body_text MEDIUMTEXT NULL,
                    received_at DATETIME NULL DEFAULT NULL,
                    has_attachments TINYINT(1) NOT NULL DEFAULT 0,
                    status VARCHAR(20) NOT NULL DEFAULT 'unmatched',
                    reason VARCHAR(255) NULL DEFAULT NULL,
                    company_id INT UNSIGNED NULL DEFAULT NULL,
                    entity_type VARCHAR(20) NULL DEFAULT NULL,
                    entity_id INT UNSIGNED NULL DEFAULT NULL,
                    contact_id INT UNSIGNED NULL DEFAULT NULL,
                    author_user_id INT UNSIGNED NULL DEFAULT NULL,
                    activity_id INT UNSIGNED NULL DEFAULT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    handled_at DATETIME NULL DEFAULT NULL,
                    UNIQUE KEY uq_gmail (gmail_id),
                    KEY ix_status (status, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $steps[] = "✓ Created `email_inbound` table.";
        } else {
            $steps[] = "• `email_inbound` already exists — skipped.";
        }
        if (!tableExists($pdo, 'notify_email_refs')) {
            $pdo->exec("
                CREATE TABLE notify_email_refs (
                    message_id VARCHAR(190) NOT NULL PRIMARY KEY,
                    company_id INT UNSIGNED NOT NULL,
                    entity_type VARCHAR(20) NULL DEFAULT NULL,
                    entity_id INT UNSIGNED NULL DEFAULT NULL,
                    contact_id INT UNSIGNED NULL DEFAULT NULL,
                    kind VARCHAR(20) NOT NULL DEFAULT '',
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY ix_entity (entity_type, entity_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $steps[] = "✓ Created `notify_email_refs` table.";
        } else {
            $steps[] = "• `notify_email_refs` already exists — skipped.";
        }
        if (!columnExists($pdo, 'notify_threads', 'email_token')) {
            $pdo->exec("ALTER TABLE notify_threads ADD COLUMN email_token VARCHAR(12) NULL DEFAULT NULL, ADD UNIQUE KEY uq_email_token (email_token)");
            $steps[] = "✓ Added notify_threads.email_token (the [J#…] subject token).";
        } else {
            $steps[] = "• notify_threads.email_token already exists — skipped.";
        }

        // 47. Client email preferences: per contact (client_contacts.notify_prefs JSON {review, replies, live} — a
        //     missing key = on; unsubscribed_at = the one-click "stop all"), and per client (notify_clients
        //     email_review / email_replies / email_live — Manage → Clients, default OFF: Joust turns them on per
        //     client once Google is connected; step 50 brings older installs in line).
        $ccAdd = [];
        if (!columnExists($pdo, 'client_contacts', 'notify_prefs'))   $ccAdd[] = "ADD COLUMN notify_prefs VARCHAR(255) NULL DEFAULT NULL";
        if (!columnExists($pdo, 'client_contacts', 'unsubscribed_at')) $ccAdd[] = "ADD COLUMN unsubscribed_at DATETIME NULL DEFAULT NULL";
        if ($ccAdd) {
            $pdo->exec("ALTER TABLE client_contacts " . implode(', ', $ccAdd));
            $steps[] = "✓ Added client_contacts email preferences.";
        } else {
            $steps[] = "• client_contacts email preferences already exist — skipped.";
        }
        $ncAdd = [];
        foreach (['email_review', 'email_replies', 'email_live'] as $col) {
            if (!columnExists($pdo, 'notify_clients', $col)) $ncAdd[] = "ADD COLUMN {$col} TINYINT(1) NOT NULL DEFAULT 0";
        }
        if ($ncAdd) {
            $pdo->exec("ALTER TABLE notify_clients " . implode(', ', $ncAdd));
            $steps[] = "✓ Added per-client email switches.";
        } else {
            $steps[] = "• Per-client email switches already exist — skipped.";
        }

        // 48. thread_seen — unread markers: per viewer (an admin user or a client contact) and item, the newest
        //     activity id they have seen. meta unread_since = the floor (nothing older shows as unread).
        if (!tableExists($pdo, 'thread_seen')) {
            $pdo->exec("
                CREATE TABLE thread_seen (
                    viewer_type VARCHAR(10) NOT NULL,
                    viewer_id INT UNSIGNED NOT NULL,
                    entity_type VARCHAR(20) NOT NULL,
                    entity_id INT UNSIGNED NOT NULL,
                    last_seen_id INT UNSIGNED NOT NULL DEFAULT 0,
                    seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (viewer_type, viewer_id, entity_type, entity_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $steps[] = "✓ Created `thread_seen` table.";
        } else {
            $steps[] = "• `thread_seen` already exists — skipped.";
        }
        $metaIns = $pdo->prepare("INSERT IGNORE INTO meta (k, v) VALUES (?, ?)");
        $metaIns->execute(['unread_since', (string)(int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM activity_log")->fetchColumn()]);
        $metaIns->execute(['client_email_since', date('Y-m-d H:i:s')]);
        if ($metaIns->rowCount() > 0) $steps[] = "✓ Seeded the unread / client email floors.";

        // 49. client_email_queue — what the client emails batch: one row per event (an item sent for review, a
        //     visible Joust reply, an item gone live / scheduled). The cron sends one email per client and kind once
        //     the batch window has passed (review 15 min after the last change, replies 10 min, live once a day) and
        //     stamps batch_key on the rows it covered.
        if (!tableExists($pdo, 'client_email_queue')) {
            $pdo->exec("
                CREATE TABLE client_email_queue (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    company_id INT UNSIGNED NOT NULL,
                    kind VARCHAR(10) NOT NULL,
                    entity_type VARCHAR(20) NOT NULL,
                    entity_id INT UNSIGNED NOT NULL,
                    activity_id INT UNSIGNED NULL DEFAULT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    batch_key VARCHAR(60) NULL DEFAULT NULL,
                    batched_at DATETIME NULL DEFAULT NULL,
                    KEY ix_open (batch_key, company_id, kind, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $steps[] = "✓ Created `client_email_queue` table.";
        } else {
            $steps[] = "• `client_email_queue` already exists — skipped.";
        }
    } catch (Exception $e) {
        $errors[] = $e->getMessage();
    }
}

// 50–51. Notification fixes: client emails start OFF, stale-review reminders, per-person notification settings.
if (!$errors) {
    try {
        // 50. Client email switches default OFF. The column default becomes 0 (a new client starts off), and on an
        //     install where step 47 already created the switches ON, every existing row is turned off — but ONLY if no
        //     client email has ever been sent (an install already emailing clients keeps its choices). Runs once:
        //     meta client_email_default_off records it; the probe is the column default.
        $defOn = 0;
        foreach (['email_review', 'email_replies', 'email_live'] as $col) {
            $st = $pdo->prepare("SELECT COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notify_clients' AND COLUMN_NAME = ?");
            $st->execute([$col]);
            if (trim((string)$st->fetchColumn(), "'") === '1') {
                $pdo->exec("ALTER TABLE notify_clients MODIFY COLUMN {$col} TINYINT(1) NOT NULL DEFAULT 0");
                $defOn++;
            }
        }
        $flag = $pdo->query("SELECT v FROM meta WHERE k = 'client_email_default_off'")->fetchColumn();
        if ($flag === false) {
            $sent = (int)$pdo->query("SELECT COUNT(*) FROM notify_outbox WHERE kind = 'client_email' AND status = 'sent'")->fetchColumn();
            $off = 0;
            if ($sent === 0) {
                $off = $pdo->exec("UPDATE notify_clients SET email_review = 0, email_replies = 0, email_live = 0 WHERE email_review <> 0 OR email_replies <> 0 OR email_live <> 0");
            }
            $pdo->prepare("INSERT INTO meta (k, v) VALUES ('client_email_default_off', ?)")->execute([date('Y-m-d H:i:s') . ($sent === 0 ? ' off:' . (int)$off : ' kept (emails already sent)')]);
            $steps[] = $sent === 0
                ? "✓ Client emails now start off: " . (int)$off . " client(s) switched off (no client email had been sent yet) — turn them on per client in Manage → Clients."
                : "✓ Client emails start off for new clients; existing switches kept ({$sent} client email(s) already sent).";
        } elseif ($defOn > 0) {
            $steps[] = "✓ Client email switches default to off.";
        } else {
            $steps[] = "• Client emails already start off — skipped.";
        }

        // 51. Stale-review reminders + per-person settings: notify_clients.email_remind (the "Gentle reminders" switch,
        //     default off like the others) and remind_days (N, default 3; 0 = off); admin_users.notify_prefs (JSON
        //     {dm, email, summary} — a missing key = on; My notifications).
        $rmAdd = [];
        if (!columnExists($pdo, 'notify_clients', 'email_remind')) $rmAdd[] = "ADD COLUMN email_remind TINYINT(1) NOT NULL DEFAULT 0";
        if (!columnExists($pdo, 'notify_clients', 'remind_days'))  $rmAdd[] = "ADD COLUMN remind_days TINYINT UNSIGNED NOT NULL DEFAULT 3";
        if ($rmAdd) {
            $pdo->exec("ALTER TABLE notify_clients " . implode(', ', $rmAdd));
            $steps[] = "✓ Added client reminder settings (notify_clients.email_remind, remind_days).";
        } else {
            $steps[] = "• Client reminder settings already exist — skipped.";
        }
        if (!columnExists($pdo, 'admin_users', 'notify_prefs')) {
            $pdo->exec("ALTER TABLE admin_users ADD COLUMN notify_prefs VARCHAR(255) NULL DEFAULT NULL");
            $steps[] = "✓ Added admin_users.notify_prefs (per-person notification settings).";
        } else {
            $steps[] = "• admin_users.notify_prefs already exists — skipped.";
        }
    } catch (Exception $e) {
        $errors[] = $e->getMessage();
    }
}

// 52. The Redo queue (redo-lib.php): images Joust has to make again, apart from the client's status. A flag on the image
//     tables, never a new status value, so the client's To Review / Approved / Needs changes and every badge stay as they
//     are. redo_at = when it joined the queue (NULL = not queued), redo_note = Joust's "what to fix" (internal, never shown
//     to a client), redo_by = the admin_users row that marked it (NULL = queued automatically by a client's Needs changes),
//     redo_exported_at = when it last went out in a redo pack ("only new since last export"). The first run also queues the
//     images that are already in Needs changes, so the queue starts with what is waiting today.
//
//     Every part is probed on its own so a re-run finishes a partial one: the columns, the index, and the one-time
//     backfill (remembered in meta redo_backfill_<table>, NOT inferred from "the columns were just added" — the first
//     staging run added tire_images' columns and then failed in the backfill, so the next run must still do it).
//     The backfill's timestamp is built only from the columns the table really has: the production tire_images has
//     updated_at (migrate.php 12) but NO created_at (the original table never had one), library_images has both.
if (!$errors) {
    try {
        $hasMeta = tableExists($pdo, 'meta');
        foreach (['tire_images', 'library_images'] as $tbl) {
            if (!tableExists($pdo, $tbl)) { $steps[] = "• Table `{$tbl}` does not exist — skipped the redo columns."; continue; }
            $add = [];
            if (!columnExists($pdo, $tbl, 'redo_at'))          $add[] = "ADD COLUMN redo_at DATETIME NULL DEFAULT NULL";
            if (!columnExists($pdo, $tbl, 'redo_note'))        $add[] = "ADD COLUMN redo_note VARCHAR(500) NULL DEFAULT NULL";
            if (!columnExists($pdo, $tbl, 'redo_by'))          $add[] = "ADD COLUMN redo_by INT UNSIGNED NULL DEFAULT NULL";
            if (!columnExists($pdo, $tbl, 'redo_exported_at')) $add[] = "ADD COLUMN redo_exported_at DATETIME NULL DEFAULT NULL";
            $ix = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'ix_redo'");
            $ix->execute([$tbl]);
            if ((int)$ix->fetchColumn() === 0) $add[] = "ADD KEY ix_redo (redo_at)";
            if ($add) {
                $pdo->exec("ALTER TABLE {$tbl} " . implode(', ', $add));
                $steps[] = "✓ Added the Redo queue to {$tbl} (" . count($add) . " change" . (count($add) === 1 ? '' : 's') . ").";
            } else {
                $steps[] = "• {$tbl} redo columns already exist — skipped.";
            }

            // One-time backfill: the images already in Needs changes join the queue.
            $marker = 'redo_backfill_' . $tbl;
            $done = false;
            if ($hasMeta) {
                $m = $pdo->prepare("SELECT 1 FROM meta WHERE k = ?");
                $m->execute([$marker]);
                $done = (bool)$m->fetchColumn();
            } else {
                $done = !$add;   // no meta table (never on a migrated install): fall back to "only when the columns were just added"
            }
            if ($done) { $steps[] = "• {$tbl} Needs-changes backfill already done — skipped."; continue; }
            $tsCols = [];
            if (columnExists($pdo, $tbl, 'updated_at')) $tsCols[] = 'updated_at';
            if (columnExists($pdo, $tbl, 'created_at')) $tsCols[] = 'created_at';
            $when = 'COALESCE(' . implode(', ', array_merge($tsCols, ['NOW()'])) . ')';
            // Keep updated_at as it was: the backfill is not an edit of the image.
            $keep = in_array('updated_at', $tsCols, true) ? ', updated_at = updated_at' : '';
            $queued = (int)$pdo->exec("UPDATE {$tbl} SET redo_at = {$when}{$keep} WHERE status = 'denied' AND redo_at IS NULL");
            if ($hasMeta) $pdo->prepare("INSERT IGNORE INTO meta (k, v) VALUES (?, ?)")->execute([$marker, date('Y-m-d H:i:s')]);
            $steps[] = "✓ Queued {$queued} {$tbl} image(s) already in Needs changes for redo.";
        }
    } catch (Exception $e) {
        $errors[] = $e->getMessage();
    }
}

// 53. Comment editing (comment-edit-lib.php): clients edit / delete their own comments (no time limit), Joust edits /
//     deletes any. The original text is never lost:
//       activity_log.edited_at / deleted_at   when the comment was last edited / deleted (a deleted comment keeps its row
//                                             — the thread shows "Comment deleted" in place — and its detail is emptied,
//                                             so every reader that skips empty comments hides it).
//       comment_revisions                     one row per edit / delete: the text before and after, who did it
//                                             (actor + admin_users id / client_contacts id) and when.
//       comment_slack                         per comment, the Slack message that carries it (channel + ts, from the
//                                             delivery), so an edit can chat.update that message. Backfilled once from
//                                             the delivered notify_outbox rows (meta comment_slack_backfill).
//     Only columns the production activity_log really has are read (id, entity_type, entity_id, action, actor, detail,
//     internal); the new tables carry their own created_at. Each part is probed on its own: a re-run finishes a partial run.
if (!$errors) {
    try {
        if (!tableExists($pdo, 'activity_log')) {
            $steps[] = "• `activity_log` does not exist — skipped comment editing.";
        } else {
            $add = [];
            if (!columnExists($pdo, 'activity_log', 'edited_at'))  $add[] = "ADD COLUMN edited_at DATETIME NULL DEFAULT NULL";
            if (!columnExists($pdo, 'activity_log', 'deleted_at')) $add[] = "ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL";
            if ($add) {
                $pdo->exec("ALTER TABLE activity_log " . implode(', ', $add));
                $steps[] = "✓ Added activity_log.edited_at / deleted_at (comment editing).";
            } else {
                $steps[] = "• activity_log.edited_at / deleted_at already exist — skipped.";
            }
            if (!tableExists($pdo, 'comment_revisions')) {
                $pdo->exec("
                    CREATE TABLE comment_revisions (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        activity_id INT UNSIGNED NOT NULL,
                        company_id INT UNSIGNED NOT NULL,
                        kind VARCHAR(10) NOT NULL,
                        old_detail TEXT NULL,
                        new_detail TEXT NULL,
                        actor VARCHAR(10) NOT NULL,
                        author_user_id INT UNSIGNED NULL DEFAULT NULL,
                        client_contact_id INT UNSIGNED NULL DEFAULT NULL,
                        created_at DATETIME NOT NULL,
                        KEY ix_activity (activity_id, id),
                        KEY ix_company (company_id, created_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
                $steps[] = "✓ Created `comment_revisions` (every edit / delete keeps the text before it).";
            } else {
                $steps[] = "• `comment_revisions` already exists — skipped.";
            }
            if (!tableExists($pdo, 'comment_slack')) {
                $pdo->exec("
                    CREATE TABLE comment_slack (
                        activity_id INT UNSIGNED NOT NULL PRIMARY KEY,
                        kind VARCHAR(20) NOT NULL,
                        slack_channel VARCHAR(40) NOT NULL,
                        slack_ts VARCHAR(40) NOT NULL,
                        outbox_id INT UNSIGNED NULL DEFAULT NULL,
                        created_at DATETIME NOT NULL,
                        KEY ix_msg (slack_channel, slack_ts)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
                $steps[] = "✓ Created `comment_slack` (the Slack message of each comment, for edits).";
            } else {
                $steps[] = "• `comment_slack` already exists — skipped.";
            }
            // One-time backfill: comments already delivered to Slack (item_event / internal_note rows that were sent) map
            // to their message — notify_outbox.provider_id is the reply's ts, notify_threads.slack_channel its channel.
            $hasMeta = tableExists($pdo, 'meta');
            $done = false;
            if ($hasMeta) {
                $m = $pdo->prepare("SELECT 1 FROM meta WHERE k = 'comment_slack_backfill'");
                $m->execute();
                $done = (bool)$m->fetchColumn();
            }
            if ($done) {
                $steps[] = "• Slack message backfill for comments already done — skipped.";
            } elseif (!tableExists($pdo, 'notify_outbox') || !tableExists($pdo, 'notify_threads')) {
                $steps[] = "• notify_outbox / notify_threads missing — no Slack messages to backfill.";
            } else {
                $ins = $pdo->prepare("INSERT IGNORE INTO comment_slack (activity_id, kind, slack_channel, slack_ts, outbox_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                $chan = $pdo->prepare("SELECT slack_channel FROM notify_threads WHERE entity_type = ? AND entity_id = ?");
                $isComment = $pdo->prepare("SELECT 1 FROM activity_log WHERE id = ? AND action = 'commented'");
                $mapped = 0; $last = 0;
                do {
                    $s = $pdo->prepare("SELECT id, kind, entity_type, entity_id, payload, provider_id FROM notify_outbox
                                         WHERE id > ? AND status = 'sent' AND channel = 'slack' AND kind IN ('item_event', 'internal_note')
                                           AND provider_id IS NOT NULL AND provider_id <> '' ORDER BY id ASC LIMIT 500");
                    $s->execute([$last]);
                    $batch = $s->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($batch as $o) {
                        $last = (int)$o['id'];
                        $p = json_decode((string)$o['payload'], true);
                        if (!is_array($p)) continue;
                        $ids = $o['kind'] === 'internal_note' ? [(int)($p['activity_id'] ?? 0)] : array_map('intval', (array)($p['activity_ids'] ?? []));
                        $chan->execute([(string)$o['entity_type'], (int)$o['entity_id']]);
                        $ch = (string)($chan->fetchColumn() ?: '');
                        if ($ch === '') continue;
                        foreach ($ids as $aid) {
                            if ($aid <= 0) continue;
                            $isComment->execute([$aid]);
                            if (!$isComment->fetchColumn()) continue;
                            $ins->execute([$aid, (string)$o['kind'], $ch, (string)$o['provider_id'], (int)$o['id']]);
                            $mapped += $ins->rowCount();
                        }
                    }
                } while (count($batch) === 500);
                if ($hasMeta) $pdo->prepare("INSERT IGNORE INTO meta (k, v) VALUES ('comment_slack_backfill', ?)")->execute([date('Y-m-d H:i:s')]);
                $steps[] = "✓ Mapped {$mapped} comment(s) already in Slack to their message.";
            }
        }
    } catch (Exception $e) {
        $errors[] = $e->getMessage();
    }
}

// 54. The Trash (trash-lib.php): items Joust will not do, kept but disregarded everywhere. A state apart from the
//     review status — never a new status value, so Restore returns the item exactly as it was:
//       trashed_at   when it went to the Trash (NULL = not trashed)      trashed_by  the admin_users row that did it
//       trash_note   the optional reason (Joust-only, never shown to a client)
//     on posts, emails, pages, tire_images and library_images (+ KEY ix_trashed). Only ADD COLUMN / ADD KEY: nothing
//     is read from or written to the existing columns (production's tables differ from the harness in places — e.g.
//     tire_images has no created_at — so no other column is ever named here). A table that does not exist yet (the
//     Emails / Pages modules on an old install) is skipped; each table and its index are probed on their own, so a
//     re-run finishes a partial run and a second run changes nothing.
if (!$errors) {
    try {
        foreach (['posts', 'emails', 'pages', 'tire_images', 'library_images'] as $tbl) {
            if (!tableExists($pdo, $tbl)) { $steps[] = "• Table `{$tbl}` does not exist — skipped the Trash columns."; continue; }
            $add = [];
            if (!columnExists($pdo, $tbl, 'trashed_at')) $add[] = "ADD COLUMN trashed_at DATETIME NULL DEFAULT NULL";
            if (!columnExists($pdo, $tbl, 'trashed_by')) $add[] = "ADD COLUMN trashed_by INT UNSIGNED NULL DEFAULT NULL";
            if (!columnExists($pdo, $tbl, 'trash_note')) $add[] = "ADD COLUMN trash_note VARCHAR(500) NULL DEFAULT NULL";
            $ix = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'ix_trashed'");
            $ix->execute([$tbl]);
            if ((int)$ix->fetchColumn() === 0) $add[] = "ADD KEY ix_trashed (trashed_at)";
            if ($add) {
                $pdo->exec("ALTER TABLE {$tbl} " . implode(', ', $add));
                $steps[] = "✓ Added the Trash to {$tbl} (" . count($add) . " change" . (count($add) === 1 ? '' : 's') . ").";
            } else {
                $steps[] = "• {$tbl} Trash columns already exist — skipped.";
            }
        }
    } catch (Exception $e) {
        $errors[] = $e->getMessage();
    }
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
