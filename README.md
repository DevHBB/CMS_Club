# ClubCMS

> CMS complet pour clubs sportifs et associatifs — PHP 8.2 + MySQL, sans framework.

---

## ✅ Fonctionnalités — Vue rapide

**Site public**
- Page d'accueil (hero, barre de stats configurable, blocs de contenu)
- Planning des créneaux avec inscription en ligne
- Forum membres (catégories, topics, modération)
- Galerie photos (dossiers, albums, lightbox)
- Médiathèque vidéos (upload local + YouTube/Vimeo, téléchargement contrôlé)
- Boutique (produits, panier, PayPal/virement, codes promo)
- Articles & actualités + pages personnalisées (éditeur de blocs)
- Newsletter (abonnement, campagnes, SMTP)
- Inscription membres + validation licences + espace membre + carte PDF
- Pop-up annonces (10 thèmes, compte à rebours)
- Traduction multilingue 10 langues (bouton 🌐 en haut à droite)
- En-têtes personnalisables sur chaque page native

**Portail bénévoles**
- Dashboard, planning événements, tâches Kanban, chat temps réel
- Documents (upload, prévisualisation, droits par fichier et par bénévole)
- Annuaire, alertes, droits granulaires par bénévole

**Administration complète**
- Membres, planning, galerie, vidéos, boutique, articles, pages, menu
- Paramètres (apparence, SMTP, paiements, reCAPTCHA, traduction, footer)
- Mise à jour automatique via GitHub (un clic)
- Migrations BDD, état des tables, FAQ intégrée

---

## 🚀 Installation

**Prérequis :** PHP 8.1+, MySQL 5.7+, extensions : `pdo_mysql` `gd` `mbstring` `curl` `zip`

> ⚠️ **Téléchargez la dernière [Release](https://github.com/DevHBB/CMS_Club/releases)** (fichier ZIP avec un tag `v1.x.x`), pas le code source brut — le bouton "Download ZIP" de GitHub donne le code non packagé qui peut être incomplet.

1. Déposez les fichiers à la racine de votre hébergement
2. Ouvrez `votresite.fr/install.php`
3. Suivez les 4 étapes (vérification → BDD → club → admin)
4. Supprimez `install.php` après installation

> L'assistant d'installation vérifie automatiquement chaque prérequis et indique comment l'activer selon votre environnement (XAMPP, cPanel, Plesk, o2switch, Hostinger…).

---

## 🔐 Rôles

`member` → `benevole` → `coach` → `admin` → `superadmin`

---

## 🔄 Mise à jour

Admin → Système → Mise à jour → **"⬇️ Mettre à jour maintenant"**

Les fichiers PHP sont mis à jour automatiquement depuis GitHub. `config/config.php` et `uploads/` ne sont jamais touchés.

---
---

## Documentation détaillée

### Page d'accueil

La page d'accueil se configure entièrement via **Admin → Pages → onglet Accueil**.

**Hero :** titre, sous-titre, image de fond, 2 boutons d'action configurables.

**Barre de statistiques :** jusqu'à 4 cases, chacune avec un type au choix :
- Auto : membres actifs, discussions forum, créneaux à venir, photos, articles, vidéos
- Personnalisé : texte libre ("150+", "Depuis 2010", "24h/24"…)
- Activable/désactivable en un toggle

**Blocs de contenu :** texte, image, colonnes, séparateur — ajoutés via l'éditeur de blocs.

---

### Planning

Créneaux avec types colorés (Libre, Entraînement, Événement, Compétition, Fermé). Les membres s'inscrivent en ligne selon des critères configurables (texte, liste, case à cocher). Export PDF. Les bénévoles peuvent s'inscrire comme encadrants — visibles dans l'onglet Inscriptions de l'admin.

---

### Médiathèque vidéos

Dossiers de vidéos configurables. Deux modes de lecture :
- **Fichier local** (mp4, webm, mov…) : lecteur HTML5 intégré. Si le téléchargement est désactivé, le clic droit et le bouton de téléchargement sont bloqués côté navigateur.
- **Embed** (YouTube, Vimeo) : coller l'URL d'intégration.

Miniature personnalisable. Accès public ou membres uniquement par dossier et par vidéo. Ajout au menu via Admin → Menu → "🎬 Vidéos".

---

### Portail bénévoles

Accessible aux rôles `benevole`, `coach`, `admin`, `superadmin`.

**Tâches Kanban :** colonnes À faire / En cours / Terminé. Nombre de bénévoles nécessaires configurable. Bouton "🙋 Je m'en charge" visible de tous — se bloque quand le quota est atteint. Les bénévoles peuvent suggérer des tâches, l'admin valide ou refuse avec une raison.

**Chat temps réel :** canaux configurables, polling toutes les 3 secondes, messages stockés en BDD. Mute par les admins.

**Documents :**
- Upload : PDF, Word, Excel, PowerPoint, images, ZIP, MP4…
- Prévisualisation inline (images) ou iframe (PDF)
- Droits de visibilité et de téléchargement par document : Tous / Coachs+ / Admins / Bénévoles spécifiques (sélection nominative)
- Qui peut uploader / créer des dossiers : configurable dans Admin → Bénévoles → onglet Documents

**Planning bénévoles :** événements bénévoles + créneaux du planning site fusionnés. Bouton "🤝 Je serai bénévole ce jour là" pour les créneaux site (liste des encadrants visible dans Admin → Planning → Inscriptions).

---

### Traduction multilingue

Activable dans Admin → Paramètres → Général. Un bouton 🌐 apparaît en haut à droite du site (position fixe, indépendant de la barre de navigation). 10 langues disponibles avec leurs drapeaux. Powered by Google Translate (gratuit, invisible pour l'utilisateur).

---

### Google reCAPTCHA v3

Admin → Paramètres → Inscription → section reCAPTCHA.

1. Créer un site sur [google.com/recaptcha/admin/create](https://www.google.com/recaptcha/admin/create) (type v3)
2. Ajouter votre domaine (ex: `monclub.fr`)
3. Copier clé site + clé secrète dans l'admin

Le captcha est invisible pour l'utilisateur (analyse comportementale, score 0–1). En local (localhost), il est automatiquement bypassé.

---

### SMTP — Configuration email

Admin → Paramètres → Emails

| Hébergeur | Hôte SMTP | Port |
|-----------|-----------|------|
| Gmail | smtp.gmail.com | 587 |
| OVH | ssl0.ovh.net | 465 |
| o2switch | mail.votredomaine.fr | 587 |
| Infomaniak | mail.infomaniak.com | 587 |
| Hostinger | smtp.hostinger.com | 587 |

---

### Mise à jour automatique

**Admin → Système → 🔄 Mise à jour**

Le CMS se connecte à `github.com/DevHBB/CMS_Club` via l'API GitHub. Si une nouvelle version (tag) est disponible, une bannière violette s'affiche avec le bouton "⬇️ Mettre à jour maintenant".

La mise à jour :
1. Télécharge le ZIP de la release (cURL ou file_get_contents selon disponibilité)
2. Extrait dans un dossier temporaire
3. Copie les fichiers **sauf** `config/config.php` et `uploads/` (jamais touchés)
4. Lance toutes les migrations BDD automatiquement
5. Affiche un journal pas à pas avec le statut de chaque étape

Pour publier une nouvelle version : créer une **Release GitHub** avec un tag `v1.5.0`.

---

### Footer personnalisable

Admin → Paramètres → Général → **Mention pied de page**

Exemples :
- `© 2025 MonClub — Site propulsé par Valentin`
- `Tous droits réservés — MonClub`

Laissé vide = texte automatique avec le nom du club.

---

### En-têtes de pages

Admin → 🏷️ En-têtes pages

Titre + sous-titre affichés dans la bannière hero de chaque page native : Planning, Forum, Galerie, Boutique, Vidéos, Actualités. Laissé vide = titre par défaut.

---

### Structure des fichiers

```
/
├── admin/          Administration
├── assets/         CSS, JS, images
├── config/         ← config.php (NE PAS ÉCRASER)
├── core/           Moteur PHP
├── install/        schema.sql
├── modules/        Pages du site (planning, forum, shop…)
├── templates/      layout.php, popup.php
├── uploads/        ← Fichiers utilisateurs (NE PAS ÉCRASER)
├── install.php     Assistant d'installation (supprimer après)
└── index.php       Front controller
```

---

*ClubCMS v1.4.0 — Développé avec ❤️*
