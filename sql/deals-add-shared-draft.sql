-- deals-add-shared-draft.sql
-- Lets an agent opt a DRAFT deal into supervisor review without formally submitting.
-- Private by default; one-way share (set by deal-share.php), cleared at submit.
-- Supervisors only see drafts where shared_draft = 1.
--
-- Load on the box:  mysql -u kofc_app -p kofc_advisor < sql/deals-add-shared-draft.sql

ALTER TABLE deals
  ADD COLUMN shared_draft TINYINT(1) NOT NULL DEFAULT 0 AFTER review_state;
