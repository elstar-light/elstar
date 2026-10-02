CREATE TABLE `requests` (
	`id` text PRIMARY KEY NOT NULL,
	`reference` text NOT NULL,
	`kind` text NOT NULL,
	`fingerprint` text NOT NULL,
	`payload` text NOT NULL,
	`status` text DEFAULT 'pending' NOT NULL,
	`source_hash` text NOT NULL,
	`created_at` integer NOT NULL,
	`updated_at` integer NOT NULL,
	`telegram_message_id` text
);
--> statement-breakpoint
CREATE INDEX `requests_source_created` ON `requests` (`source_hash`,`created_at`);