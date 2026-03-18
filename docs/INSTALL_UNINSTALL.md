•	Activation: create only missing plugin tables/dirs if truly absent; otherwise no op. Never alter customer schema.
•	Uninstall: register_uninstall_hook( __FILE__, ['MW_Audit_Install','uninstall'] );
o	Read mw_audit_uninstall_drop option. If yes → drop plugin tables; else keep.
o	No closures, no anonymous callables.
