<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class FixUserSoftDeleteUniqueness extends Migration
{
    public function up(): void
    {
        // 1. Drop existing unique constraint indexes
        $this->db->query("ALTER TABLE `users` DROP INDEX `username`");
        $this->db->query("ALTER TABLE `users` DROP INDEX `email`");
        $this->db->query("ALTER TABLE `users` DROP INDEX `registration_number`");
        $this->db->query("ALTER TABLE `users` DROP INDEX `ssn`");

        // 2. Add generated virtual columns for active status uniqueness
        // These virtual columns evaluate to the value if deleted_at IS NULL, otherwise they are NULL.
        // In MariaDB/MySQL, NULL is not subject to UNIQUE constraints (multiple NULL values are allowed).
        $this->db->query("ALTER TABLE `users` ADD `active_username` VARCHAR(100) GENERATED ALWAYS AS (IF(`deleted_at` IS NULL, `username`, NULL)) VIRTUAL");
        $this->db->query("ALTER TABLE `users` ADD `active_email` VARCHAR(255) GENERATED ALWAYS AS (IF(`deleted_at` IS NULL, `email`, NULL)) VIRTUAL");
        $this->db->query("ALTER TABLE `users` ADD `active_registration_number` VARCHAR(100) GENERATED ALWAYS AS (IF(`deleted_at` IS NULL, `registration_number`, NULL)) VIRTUAL");
        $this->db->query("ALTER TABLE `users` ADD `active_ssn` VARCHAR(100) GENERATED ALWAYS AS (IF(`deleted_at` IS NULL, `ssn`, NULL)) VIRTUAL");

        // 3. Add unique constraints on the virtual columns
        $this->db->query("ALTER TABLE `users` ADD UNIQUE INDEX `uq_active_username` (`active_username`)");
        $this->db->query("ALTER TABLE `users` ADD UNIQUE INDEX `uq_active_email` (`active_email`)");
        $this->db->query("ALTER TABLE `users` ADD UNIQUE INDEX `uq_active_registration_number` (`active_registration_number`)");
        $this->db->query("ALTER TABLE `users` ADD UNIQUE INDEX `uq_active_ssn` (`active_ssn`)");
    }

    public function down(): void
    {
        // 1. Drop unique indexes on virtual columns
        $this->db->query("ALTER TABLE `users` DROP INDEX `uq_active_username`");
        $this->db->query("ALTER TABLE `users` DROP INDEX `uq_active_email`");
        $this->db->query("ALTER TABLE `users` DROP INDEX `uq_active_registration_number`");
        $this->db->query("ALTER TABLE `users` DROP INDEX `uq_active_ssn`");

        // 2. Drop generated virtual columns
        $this->db->query("ALTER TABLE `users` DROP COLUMN `active_username`");
        $this->db->query("ALTER TABLE `users` DROP COLUMN `active_email`");
        $this->db->query("ALTER TABLE `users` DROP COLUMN `active_registration_number`");
        $this->db->query("ALTER TABLE `users` DROP COLUMN `active_ssn`");

        // 3. Restore old unique indexes
        $this->db->query("ALTER TABLE `users` ADD UNIQUE INDEX `username` (`username`)");
        $this->db->query("ALTER TABLE `users` ADD UNIQUE INDEX `email` (`email`)");
        $this->db->query("ALTER TABLE `users` ADD UNIQUE INDEX `registration_number` (`registration_number`)");
        $this->db->query("ALTER TABLE `users` ADD UNIQUE INDEX `ssn` (`ssn`)");
    }
}
