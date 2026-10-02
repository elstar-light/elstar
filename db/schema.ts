// Intentionally empty by default.
// Add Drizzle tables here when the site actually needs a database.
// See examples/d1/db/schema.ts for an opt-in example.
import {sqliteTable,text,integer,index} from 'drizzle-orm/sqlite-core';
export const requests = sqliteTable('requests', {
 id:text('id').primaryKey(),
 reference:text('reference').notNull(),
 kind:text('kind').notNull(),
 fingerprint:text('fingerprint').notNull(),
 payload:text('payload').notNull(),
 status:text('status').notNull().default('pending'),
 sourceHash:text('source_hash').notNull(),
 createdAt:integer('created_at').notNull(),
 updatedAt:integer('updated_at').notNull(),
 telegramMessageId:text('telegram_message_id'),
},t=>[index('requests_source_created').on(t.sourceHash,t.createdAt)]);
