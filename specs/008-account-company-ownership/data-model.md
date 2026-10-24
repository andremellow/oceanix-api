# Data model

No schema changes, backfill, deletion or identity mutation. Preserve Company, User, roles, assignments, certificates, events, AccountCompanyBinding and ProvisioningReceipt structures and lifecycle. The existing Account provisioning receiver remains the only supported runtime tenant creation path after removal of the legacy UI/command Actions. Factories/seeders remain test/setup tools and are not rewritten.
