-- Phase 1 DOWN Migration: Rollback settings and import_runs tables
-- Run this to undo Phase 1 changes

DROP TABLE IF EXISTS import_runs;
DROP TABLE IF EXISTS settings;
