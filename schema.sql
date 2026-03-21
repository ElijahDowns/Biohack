CREATE DATABASE IF NOT EXISTS s2837201_biohack;
USE s2837201_biohack;

DROP TABLE IF EXISTS bio_metadata;
DROP TABLE IF EXISTS jobs;

CREATE TABLE jobs (
    job_id            VARCHAR(64)     NOT NULL PRIMARY KEY,
    session_id        VARCHAR(64)     NOT NULL,
    genome_filename   VARCHAR(255)    NOT NULL DEFAULT '',
    organism          VARCHAR(255)    NOT NULL DEFAULT '',
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
    status            VARCHAR(20)     NOT NULL DEFAULT 'pending',
    status_message    TEXT            DEFAULT NULL,
    taxon_detected    VARCHAR(255)    DEFAULT NULL,
    taxon_id          INT UNSIGNED    DEFAULT NULL,
    organism_type     VARCHAR(20)     DEFAULT NULL,
    model_xml         MEDIUMTEXT      DEFAULT NULL,
    submitted_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME        DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    completed_at      DATETIME        DEFAULT NULL,
    is_example        TINYINT(1)      NOT NULL DEFAULT 0
);

CREATE TABLE bio_metadata (
    meta_id               INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
    job_id                VARCHAR(64)     NOT NULL,
    organism_type         VARCHAR(20)     DEFAULT NULL,
    maintenance_coeff     DECIMAL(6,3)    DEFAULT NULL,
    required_supplements  VARCHAR(255)    DEFAULT NULL,   -- comma-separated
    tax_id                INT UNSIGNED    DEFAULT NULL,
    FOREIGN KEY (job_id) REFERENCES jobs(job_id)
);

CREATE INDEX idx_jobs_session   ON jobs(session_id);
CREATE INDEX idx_jobs_status    ON jobs(status);
CREATE INDEX idx_jobs_submitted ON jobs(submitted_at);
CREATE INDEX idx_jobs_example   ON jobs(is_example);
CREATE INDEX idx_meta_job       ON bio_metadata(job_id);

SHOW TABLES;
