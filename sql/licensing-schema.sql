-- licensing-schema.sql
-- Structured, state-keyed licensing lookup for the KofC advisor (DB: kofc_advisor).
-- Authoritative facts injected directly into prompt context, bypassing the vector store.
-- Verify-only fields (prelicensing_hours, ltc_training, ce_cycle) are left NULL on purpose:
-- NULL renders as a 'verify at DOI' hand-off. Fill a value only after confirming at the source.

CREATE TABLE IF NOT EXISTS licensing_state_requirements (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  state_code         CHAR(2)      NOT NULL,
  state_name         VARCHAR(64)  NOT NULL,
  regulator          VARCHAR(128) NOT NULL,
  doi_url            VARCHAR(255) NOT NULL,
  annuity_bi_adopted ENUM('yes','no','pending','verify') NOT NULL DEFAULT 'verify',
  annuity_bi_note    VARCHAR(255) NULL,
  annuity_training   VARCHAR(255) NULL,
  prelicensing_hours VARCHAR(160) NULL,
  ltc_training       VARCHAR(160) NULL,
  ce_cycle           VARCHAR(160) NULL,
  updated_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_state_code (state_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Idempotent seed. Re-running updates the sourced columns and leaves verify-fields untouched
-- unless you have set them (COALESCE keeps any value you have already confirmed).
INSERT INTO licensing_state_requirements
  (state_code, state_name, regulator, doi_url, annuity_bi_adopted, annuity_bi_note, annuity_training)
VALUES
  ('AL','Alabama','Alabama Department of Insurance','https://www.aldoi.gov','yes',NULL,'4 hrs for a new agent; 1 hr supplement if previously trained under the prior model'),
  ('AK','Alaska','Alaska Division of Insurance','https://www.commerce.alaska.gov/web/ins','yes',NULL,'4 hrs for a new agent; 1 hr supplement if previously trained under the prior model'),
  ('AZ','Arizona','Arizona Department of Insurance & Financial Institutions','https://difi.az.gov','yes',NULL,'4 hrs for a new agent; 1 hr supplement if previously trained under the prior model'),
  ('AR','Arkansas','Arkansas Insurance Department','https://insurance.arkansas.gov','yes',NULL,'4 hrs for a new agent; 1 hr supplement if previously trained under the prior model'),
  ('CA','California','California Department of Insurance','https://www.insurance.ca.gov','yes',NULL,'8 hrs initial, plus a 4-hr annuity CE course every 2 years'),
  ('CO','Colorado','Colorado Division of Insurance','https://doi.colorado.gov','yes',NULL,'4 hrs for a new agent; 1 hr supplement if previously trained under the prior model'),
  ('CT','Connecticut','Connecticut Insurance Department','https://portal.ct.gov/cid','yes',NULL,'4 hrs for a new agent; 1 hr supplement if previously trained under the prior model'),
  ('DE','Delaware','Delaware Department of Insurance','https://insurance.delaware.gov','yes',NULL,'4 hrs for a new agent; 1 hr supplement if previously trained under the prior model'),
  ('DC','District of Columbia','DC Department of Insurance, Securities & Banking','https://disb.dc.gov','verify','Adoption status not confirmed in source; verify directly.',NULL),
  ('FL','Florida','Florida Department of Financial Services','https://www.myfloridacfo.com','yes',NULL,'4 hrs for a new agent; 1 hr supplement if previously trained under the prior model'),
  ('GA','Georgia','Georgia Office of Insurance & Safety Fire Commissioner','https://oci.georgia.gov','yes',NULL,'4 hrs for a new agent; 1 hr supplement if previously trained under the prior model'),
  ('HI','Hawaii','Hawaii Insurance Division','https://cca.hawaii.gov/ins','yes',NULL,'4 hrs for a new agent; 1 hr supplement if previously trained under the prior model'),
  ('ID','Idaho','Idaho Department of Insurance','https://doi.idaho.gov','yes',NULL,'4 hrs for a new agent; 1 hr supplement if previously trained under the prior model'),
  ('IL','Illinois','Illinois Department of Insurance','https://idoi.illinois.gov','yes',NULL,'4 hrs for a new agent; 1 hr supplement if previously trained under the prior model'),
  ('IN','Indiana','Indiana Department of Insurance','https://www.in.gov/idoi','yes',NULL,'4 hrs for a new agent; 1 hr supplement if previously trained under the prior model'),
  ('IA','Iowa','Iowa Insurance Division','https://iid.iowa.gov','yes',NULL,'4 hrs for a new agent; 1 hr supplement if previously trained under the prior model'),
  ('KS','Kansas','Kansas Insurance Department','https://insurance.kansas.gov','yes',NULL,'4 hrs for a new agent; 1 hr supplement if previously trained under the prior model'),
  ('KY','Kentucky','Kentucky Department of Insurance','https://insurance.ky.gov','yes',NULL,'4 hrs for a new agent; 1 hr supplement if previously trained under the prior model'),
  ('LA','Louisiana','Louisiana Department of Insurance','https://www.ldi.la.gov','yes',NULL,'4 hrs for a new agent; 1 hr supplement if previously trained under the prior model'),
  ('ME','Maine','Maine Bureau of Insurance','https://www.maine.gov/pfr/insurance','yes',NULL,'4 hrs for a new agent; 1 hr supplement if previously trained under the prior model'),
  ('MD','Maryland','Maryland Insurance Administration','https://insurance.maryland.gov','yes',NULL,'4 hrs for a new agent; 1 hr supplement if previously trained under the prior model'),
  ('MA','Massachusetts','Massachusetts Division of Insurance','https://www.mass.gov/orgs/division-of-insurance','yes',NULL,'4 hrs for a new agent; 1 hr supplement if previously trained under the prior model'),
  ('MI','Michigan','Michigan Department of Insurance & Financial Services','https://www.michigan.gov/difs','yes',NULL,'4 hrs for a new agent; 1 hr supplement if previously trained under the prior model'),
  ('MN','Minnesota','Minnesota Department of Commerce','https://mn.gov/commerce','yes',NULL,'4 hrs for a new agent; 1 hr supplement if previously trained under the prior model'),
  ('MS','Mississippi','Mississippi Insurance Department','https://www.mid.ms.gov','yes',NULL,'4 hrs for a new agent; 1 hr supplement if previously trained under the prior model'),
  ('MO','Missouri','Missouri Department of Commerce & Insurance','https://insurance.mo.gov','yes',NULL,'4 hrs for a new agent; 1 hr supplement if previously trained under the prior model'),
  ('MT','Montana','Montana Commissioner of Securities & Insurance','https://csimt.gov','yes',NULL,'4 hrs for a new agent; 1 hr supplement if previously trained under the prior model'),
  ('NE','Nebraska','Nebraska Department of Insurance','https://doi.nebraska.gov','yes',NULL,'4 hrs for a new agent; 1 hr supplement if previously trained under the prior model'),
  ('NV','Nevada','Nevada Division of Insurance','https://doi.nv.gov','yes',NULL,'4 hrs for a new agent; 1 hr supplement if previously trained under the prior model'),
  ('NH','New Hampshire','New Hampshire Insurance Department','https://www.nh.gov/insurance','yes',NULL,'4 hrs for a new agent; 1 hr supplement if previously trained under the prior model'),
  ('NJ','New Jersey','New Jersey Department of Banking & Insurance','https://www.nj.gov/dobi','pending','Revised NAIC model adoption in progress; verify current rule.','Revised-model course updates in progress; verify at DOI'),
  ('NM','New Mexico','New Mexico Office of Superintendent of Insurance','https://www.osi.state.nm.us','yes',NULL,'4 hrs for a new agent; 1 hr supplement if previously trained under the prior model'),
  ('NY','New York','New York Department of Financial Services','https://www.dfs.ny.gov','no','Regulated under NY Regulation 187 instead of the revised NAIC model.','Per Regulation 187: no fixed hour count; training responsibility placed on the insurer'),
  ('NC','North Carolina','North Carolina Department of Insurance','https://www.ncdoi.gov','yes',NULL,'4 hrs for a new agent; 1 hr supplement if previously trained under the prior model'),
  ('ND','North Dakota','North Dakota Insurance Department','https://www.insurance.nd.gov','yes',NULL,'4 hrs for a new agent; 1 hr supplement if previously trained under the prior model'),
  ('OH','Ohio','Ohio Department of Insurance','https://insurance.ohio.gov','yes',NULL,'4 hrs for a new agent; 1 hr supplement if previously trained under the prior model'),
  ('OK','Oklahoma','Oklahoma Insurance Department','https://www.oid.ok.gov','yes',NULL,'4 hrs for a new agent; 1 hr supplement if previously trained under the prior model'),
  ('OR','Oregon','Oregon Division of Financial Regulation','https://dfr.oregon.gov','yes',NULL,'4 hrs for a new agent; 1 hr supplement if previously trained under the prior model'),
  ('PA','Pennsylvania','Pennsylvania Insurance Department','https://www.insurance.pa.gov','yes',NULL,'4 hrs for a new agent; 1 hr supplement if previously trained under the prior model'),
  ('RI','Rhode Island','Rhode Island Department of Business Regulation','https://dbr.ri.gov','yes',NULL,'4 hrs for a new agent; 1 hr supplement if previously trained under the prior model'),
  ('SC','South Carolina','South Carolina Department of Insurance','https://doi.sc.gov','yes',NULL,'4 hrs for a new agent; 1 hr supplement if previously trained under the prior model'),
  ('SD','South Dakota','South Dakota Division of Insurance','https://dlr.sd.gov/insurance','yes',NULL,'4 hrs for a new agent; 1 hr supplement if previously trained under the prior model'),
  ('TN','Tennessee','Tennessee Department of Commerce & Insurance','https://www.tn.gov/commerce','yes',NULL,'4 hrs for a new agent; 1 hr supplement if previously trained under the prior model'),
  ('TX','Texas','Texas Department of Insurance','https://www.tdi.texas.gov','yes',NULL,'4 hrs for a new agent; 1 hr supplement if previously trained under the prior model'),
  ('UT','Utah','Utah Insurance Department','https://insurance.utah.gov','yes',NULL,'4 hrs for a new agent; 1 hr supplement if previously trained under the prior model'),
  ('VT','Vermont','Vermont Department of Financial Regulation','https://dfr.vermont.gov','yes',NULL,'4 hrs for a new agent; 1 hr supplement if previously trained under the prior model'),
  ('VA','Virginia','Virginia Bureau of Insurance (State Corporation Commission)','https://scc.virginia.gov/pages/Bureau-of-Insurance','yes',NULL,'4 hrs for a new agent; 1 hr supplement if previously trained under the prior model'),
  ('WA','Washington','Washington Office of the Insurance Commissioner','https://www.insurance.wa.gov','yes',NULL,'4 hrs for a new agent; 1 hr supplement if previously trained under the prior model'),
  ('WV','West Virginia','West Virginia Offices of the Insurance Commissioner','https://www.wvinsurance.gov','yes',NULL,'4 hrs for a new agent; 1 hr supplement if previously trained under the prior model'),
  ('WI','Wisconsin','Wisconsin Office of the Commissioner of Insurance','https://oci.wi.gov','yes',NULL,'4 hrs for a new agent; 1 hr supplement if previously trained under the prior model'),
  ('WY','Wyoming','Wyoming Department of Insurance','https://doi.wyo.gov','yes',NULL,'4 hrs for a new agent; 1 hr supplement if previously trained under the prior model')
ON DUPLICATE KEY UPDATE
  state_name         = VALUES(state_name),
  regulator          = VALUES(regulator),
  doi_url            = VALUES(doi_url),
  annuity_bi_adopted = VALUES(annuity_bi_adopted),
  annuity_bi_note    = VALUES(annuity_bi_note),
  annuity_training   = VALUES(annuity_training);
