<?php
// Separate WhatsApp number per person (NULL = use the phone number).
return ['up' => ["ALTER TABLE users ADD COLUMN whatsapp VARCHAR(40) NULL AFTER phone"], 'down' => ["ALTER TABLE users DROP COLUMN whatsapp"]];
