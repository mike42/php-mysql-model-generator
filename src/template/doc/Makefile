# Compile doxygen files and diagrams

default:
	doxygen doxy.conf
	for i in diagram/*.dot; do dot -Tpdf $$i > $${i%.*}.pdf; done

clean:
	rm --preserve-root -Rf html latex doxygen_sqlite3.db diagram/*.pdf

