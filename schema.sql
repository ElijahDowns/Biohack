-- GEMgen MySQL Schema
-- Run once: mysql -u s2837201 -pElijah271202? < schema.sql
-- ─────────────────────────────────────────────────────────────────────────────

CREATE DATABASE IF NOT EXISTS s2837201_biohack;
USE s2837201_biohack;

-- ── Drop in dependency order ──────────────────────────────────────────────────
DROP TABLE IF EXISTS bio_metadata;
DROP TABLE IF EXISTS jobs;

-- ── jobs ──────────────────────────────────────────────────────────────────────
-- One row per pipeline run submitted by a user

CREATE TABLE jobs (
    job_id            VARCHAR(64)     NOT NULL PRIMARY KEY,
    session_id        VARCHAR(64)     NOT NULL,

    -- Genome input
    genome_filename   VARCHAR(255)    NOT NULL DEFAULT '',
    organism          VARCHAR(255)    NOT NULL DEFAULT '',

    -- Media / bioreactor parameters
    carbon_source     VARCHAR(100)    NOT NULL DEFAULT '',
    carbon_conc       DECIMAL(10,3)   DEFAULT NULL,
    carbon_unit       VARCHAR(20)     NOT NULL DEFAULT 'g/L',
    nitrogen_source   VARCHAR(100)    NOT NULL DEFAULT '',
    nitrogen_conc     DECIMAL(10,3)   DEFAULT NULL,
    nitrogen_unit     VARCHAR(20)     NOT NULL DEFAULT 'g/L',
    ph                DECIMAL(4,2)    DEFAULT NULL,
    temperature       VARCHAR(20)     DEFAULT NULL,
    rpm               VARCHAR(20)     DEFAULT NULL,
    volume            VARCHAR(20)     DEFAULT NULL,
    duration          DECIMAL(6,1)    DEFAULT NULL,
    inoculum          DECIMAL(5,2)    DEFAULT NULL,

    -- Pipeline status
    status            VARCHAR(20)     NOT NULL DEFAULT 'pending',
    status_message    TEXT            DEFAULT NULL,

    -- BV-BRC results
    taxon_detected    VARCHAR(255)    DEFAULT NULL,
    taxon_id          INT UNSIGNED    DEFAULT NULL,
    organism_type     VARCHAR(20)     DEFAULT NULL,

    -- SBML model (MEDIUMTEXT = up to 16 MB)
    model_xml         MEDIUMTEXT      DEFAULT NULL,

    -- Timestamps
    submitted_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME        DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    completed_at      DATETIME        DEFAULT NULL,

    -- Example dataset flag
    is_example        TINYINT(1)      NOT NULL DEFAULT 0
);

-- ── bio_metadata ──────────────────────────────────────────────────────────────
-- Stores the bio_metadata dict returned by pipeline.py constraints()
-- Kept separate so it can be extended without altering the jobs table

CREATE TABLE bio_metadata (
    meta_id               INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
    job_id                VARCHAR(64)     NOT NULL,
    organism_type         VARCHAR(20)     DEFAULT NULL,
    maintenance_coeff     DECIMAL(6,3)    DEFAULT NULL,
    required_supplements  VARCHAR(255)    DEFAULT NULL,   -- comma-separated
    tax_id                INT UNSIGNED    DEFAULT NULL,
    FOREIGN KEY (job_id) REFERENCES jobs(job_id)
);

-- ── Indexes ───────────────────────────────────────────────────────────────────

-- History page: find all jobs for a session
CREATE INDEX idx_jobs_session   ON jobs(session_id);

-- Status polling: find running jobs quickly
CREATE INDEX idx_jobs_status    ON jobs(status);

-- Timeline view: sort by submission time
CREATE INDEX idx_jobs_submitted ON jobs(submitted_at);

-- Example dataset lookup
CREATE INDEX idx_jobs_example   ON jobs(is_example);

-- bio_metadata lookup by job
CREATE INDEX idx_meta_job       ON bio_metadata(job_id);

SHOW TABLES;
