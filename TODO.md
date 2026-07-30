# Containeur Tester - Unraid Plugin

## Plan aprobat ✅

### Obiective
Plugin Unraid pentru testarea actualizărilor de containere Docker înainte de a le aplica celor principale.

### Structura fișierelor

#### Configurare și definire plugin
- [ ] `containeur-tester.plg` - Fișierul principal XML al plugin-ului
- [ ] `source/containeur-tester/usr/local/emhttp/plugins/containeur-tester/default.cfg` - Configurație implicită

#### Backend (PHP + Bash)
- [ ] `source/containeur-tester/usr/local/emhttp/plugins/containeur-tester/include/containeur-tester.php` - Backend PHP principal (API endpoints, operațiuni Docker)
- [ ] `source/containeur-tester/usr/local/emhttp/plugins/containeur-tester/scripts/test-container.sh` - Script bash principal (pull imagine, creare canary, verificare stare, promovare/rollback)

#### Frontend (Dashboard Web)
- [ ] `source/containeur-tester/usr/local/emhttp/plugins/containeur-tester/containeur-tester.page` - Definire pagină Unraid
- [ ] `source/containeur-tester/usr/local/emhttp/plugins/containeur-tester/javascript/containeur-tester.js` - Frontend logic (listă containere, stare teste, istoric)
- [ ] `source/containeur-tester/usr/local/emhttp/plugins/containeur-tester/css/containeur-tester.css` - Stilizare dashboard
- [ ] `source/containeur-tester/usr/local/emhttp/plugins/containeur-tester/README.md` - Documentație

### Funcționalități principale
1. **Dashboard Dashboard** - pagină web în interfața Unraid
2. **Listare containere** - afișează toate containerele care rulează cu tag-urile lor curente
3. **Testare actualizare** - per container sau automat pentru toate
4. **Monitorizare stare** - verifică dacă containerul de test rulează corect
5. **Promovare/Rollback** - dacă testul trece, actualizează containerul original; dacă eșuează, anulează
6. **Programare automată** - verificări periodice prin cron
7. **Istoric** - tabel cu toate testele efectuate

## Task curent: plugin nu apare în Unraid
- [x] Investigat fișierele relevante (`.plg`, `.page`, `include`, `README`)
- [x] Modificat meniul paginii în `containeur-tester.page` la categorie validă Unraid (`Tools`)
- [x] Confirmat pașii de refresh/reinstall pentru a forța reindexarea meniului în WebUI
- [ ] Aliniere la implementarea NVIDIA pentru menu launch în `.plg`
- [ ] Adăugare atribut `launch` în `source/containeur-tester/containeur-tester.plg`
- [ ] Validare poziționare menu (`Tools/containeur-tester`)
