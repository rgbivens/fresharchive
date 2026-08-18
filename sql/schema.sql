-- Freshdesk archive schema
-- Designed around the actual structure of the Freshdesk "Tickets*.json" export.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS companies (
    id BIGINT UNSIGNED PRIMARY KEY,
    name VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `groups` (
    id BIGINT UNSIGNED PRIMARY KEY,
    name VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS agents (
    id BIGINT UNSIGNED PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS requesters (
    id BIGINT UNSIGNED PRIMARY KEY,
    name VARCHAR(255) NULL,
    email VARCHAR(255) NULL,
    company_id BIGINT UNSIGNED NULL,
    phone VARCHAR(64) NULL,
    KEY idx_requesters_company (company_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tickets (
    id BIGINT UNSIGNED PRIMARY KEY,
    display_id BIGINT UNSIGNED NULL,
    subject VARCHAR(1000) NULL,
    description MEDIUMTEXT NULL,
    description_html MEDIUMTEXT NULL,
    status SMALLINT NULL,
    status_name VARCHAR(64) NULL,
    priority SMALLINT NULL,
    priority_name VARCHAR(64) NULL,
    source_name VARCHAR(64) NULL,
    requester_id BIGINT UNSIGNED NULL,
    requester_name VARCHAR(255) NULL,
    responder_id BIGINT UNSIGNED NULL,
    responder_name VARCHAR(255) NULL,
    group_id BIGINT UNSIGNED NULL,
    company_id BIGINT UNSIGNED NULL,
    to_email VARCHAR(255) NULL,
    urgent TINYINT(1) DEFAULT 0,
    spam TINYINT(1) DEFAULT 0,
    deleted TINYINT(1) DEFAULT 0,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    due_by DATETIME NULL,

    -- generated column mirrors `description` so FULLTEXT can index it alongside subject
    FULLTEXT KEY ft_subject_description (subject, description),

    KEY idx_tickets_status (status),
    KEY idx_tickets_priority (priority),
    KEY idx_tickets_requester (requester_id),
    KEY idx_tickets_responder (responder_id),
    KEY idx_tickets_group (group_id),
    KEY idx_tickets_created (created_at),
    KEY idx_tickets_display_id (display_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notes (
    id BIGINT UNSIGNED PRIMARY KEY,
    ticket_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    body MEDIUMTEXT NULL,
    body_html MEDIUMTEXT NULL,
    private TINYINT(1) DEFAULT 0,
    incoming TINYINT(1) DEFAULT 0,
    created_at DATETIME NULL,

    FULLTEXT KEY ft_body (body),
    KEY idx_notes_ticket (ticket_id),
    CONSTRAINT fk_notes_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tags (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ticket_tags (
    ticket_id BIGINT UNSIGNED NOT NULL,
    tag_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (ticket_id, tag_id),
    CONSTRAINT fk_tt_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_tt_tag FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS attachments (
    id BIGINT UNSIGNED PRIMARY KEY,
    ticket_id BIGINT UNSIGNED NOT NULL,
    note_id BIGINT UNSIGNED NULL,
    filename VARCHAR(500) NOT NULL,
    content_type VARCHAR(255) NULL,
    file_size INT UNSIGNED NULL,
    local_path VARCHAR(1000) NULL,

    KEY idx_attach_ticket (ticket_id),
    KEY idx_attach_note (note_id),
    CONSTRAINT fk_attach_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB;
