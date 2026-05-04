#!/bin/bash
# ================================================================
# setup/server_setup.sh — Automated Server Setup for Resources Platform
#
# Run this script on the Hostinger VPS via SSH:
#   bash /path/to/server_setup.sh
#
# Prerequisites:
#   - SSH access to YOUR_USER@YOUR_SERVER:PORT
#   - MySQL DB your_db_name already created (via Hostinger panel)
# ================================================================

set -e

REPO_PATH="/home/YOUR_USER/repos/resources.git"
DOC_ROOT="/home/YOUR_USER/domains/YOUR_DOMAIN/public_html"
CAMPUS_ROOT="/home/YOUR_USER/domains/YOUR_CAMPUS_DOMAIN/public_html/edu"

echo "════════════════════════════════════════"
echo "  Resources Platform — Server Setup"
echo "════════════════════════════════════════"

# ── 1. Create bare repo ──────────────────────────────────────
echo ""
echo "▶ Step 1: Creating bare git repository..."
if [ -d "$REPO_PATH" ]; then
    echo "  ⚠ Repo already exists at $REPO_PATH — skipping"
else
    mkdir -p "$REPO_PATH"
    cd "$REPO_PATH"
    git init --bare
    echo "  ✅ Bare repo created"
fi

# ── 2. Create deploy hook ────────────────────────────────────
echo ""
echo "▶ Step 2: Setting up deploy hook..."
cat > "$REPO_PATH/hooks/post-receive" << 'HOOK'
#!/bin/bash
TARGET="/home/YOUR_USER/domains/YOUR_DOMAIN/public_html"
GIT_DIR="/home/YOUR_USER/repos/resources.git"

echo ""
echo "🚀 Deploying Resources Platform..."
git --work-tree=$TARGET --git-dir=$GIT_DIR checkout -f main
echo "✅ Deploy complete! $(date)"
echo ""
HOOK
chmod +x "$REPO_PATH/hooks/post-receive"
echo "  ✅ Deploy hook created"

# ── 3. Generate JWT secret ───────────────────────────────────
echo ""
echo "▶ Step 3: Generating JWT secret..."
JWT_SECRET=$(php -r "echo bin2hex(random_bytes(32));")
echo "  ✅ JWT Secret generated: $JWT_SECRET"
echo ""
echo "  ⚠ IMPORTANT: Add this to BOTH .env.php files:"
echo "     Resources: $DOC_ROOT/.env.php → 'JWT_SECRET' => '$JWT_SECRET'"
echo "     Campus:    $CAMPUS_ROOT/.env.php → 'resources_jwt_secret' => '$JWT_SECRET'"

# ── 4. Create .env.php ───────────────────────────────────────
echo ""
echo "▶ Step 4: Creating .env.php..."
if [ -f "$DOC_ROOT/.env.php" ]; then
    echo "  ⚠ .env.php already exists — skipping (update JWT_SECRET manually)"
else
    mkdir -p "$DOC_ROOT"
    cat > "$DOC_ROOT/.env.php" << ENVEOF
<?php return [
    'DB_HOST' => 'localhost',
    'DB_NAME' => 'your_database_name',
    'DB_USER' => 'your_database_user',
    'DB_PASS' => '*** SET YOUR DB PASSWORD HERE ***',
    'JWT_SECRET' => '$JWT_SECRET',
    'ALLOWED_ORIGINS' => [
        'https://claseprivada.com',
        'https://staging.claseprivada.com',
    ],
];
ENVEOF
    echo "  ✅ .env.php created with generated JWT_SECRET"
fi

# ── 5. Copy QBank ────────────────────────────────────────────
echo ""
echo "▶ Step 5: Checking QBank..."
if [ -d "$CAMPUS_ROOT/questionbank" ]; then
    if [ -d "$DOC_ROOT/questionbank" ]; then
        echo "  ⚠ questionbank/ already exists in resources — skipping"
    else
        echo "  Copying questionbank from Campus..."
        cp -r "$CAMPUS_ROOT/questionbank/" "$DOC_ROOT/questionbank/"
        echo "  ✅ QBank copied ($(du -sh $DOC_ROOT/questionbank/ | cut -f1))"
    fi
else
    echo "  ⚠ No questionbank/ found in Campus — skipping"
fi

# ── 6. Summary ───────────────────────────────────────────────
echo ""
echo "════════════════════════════════════════"
echo "  Setup Complete!"
echo "════════════════════════════════════════"
echo ""
echo "  Next steps:"
echo "  1. From your local machine, add the remote:"
echo "     git remote add origin ssh://YOUR_USER@YOUR_SERVER:PORT$REPO_PATH"
echo ""
echo "  2. Push to deploy:"
echo "     git push origin main"
echo ""
echo "  3. Run schema.sql:"
echo "     mysql -u your_db_user -p your_db_name < $DOC_ROOT/setup/schema.sql"
echo ""
echo "  4. Add JWT_SECRET to Campus .env.php:"
echo "     'resources_jwt_secret' => '$JWT_SECRET'"
echo ""
echo "  5. Verify:"
echo "     curl https://resources.claseprivada.com/"
echo ""
