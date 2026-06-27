#!/usr/bin/env sh
# Diagnóstico: por qué staging no refleja el checkout nuevo.
# Ejecutar en la raíz del repo: sh scripts/check-staging-deploy.sh

set -e
cd "$(dirname "$0")/.."

echo "=== Rama actual ==="
git branch -vv

echo ""
echo "=== Commits en update-checkout que NO están en main ==="
git log origin/main..origin/update-checkout --oneline 2>/dev/null || git log main..update-checkout --oneline

echo ""
echo "=== ¿Existe barra flotante en origin/update-checkout? ==="
if git show origin/update-checkout:resources/js/Components/Checkout/CheckoutWizardFloatingFooter.jsx >/dev/null 2>&1; then
  echo "OK: CheckoutWizardFloatingFooter.jsx está en origin/update-checkout"
else
  echo "FALTA: CheckoutWizardFloatingFooter.jsx NO está en origin/update-checkout (¿sin commit/push?)"
fi

if git show origin/update-checkout:resources/js/Pages/LaboratoryCheckout.jsx 2>/dev/null | grep -q floatingWizardFooter; then
  echo "OK: floatingWizardFooter en LaboratoryCheckout (remoto)"
else
  echo "FALTA: floatingWizardFooter en LaboratoryCheckout (remoto)"
fi

echo ""
echo "=== ¿Main tiene el wizard de checkout? ==="
if git show origin/main:resources/js/Pages/LaboratoryCheckout.jsx 2>/dev/null | grep -q WIZARD_STEPS; then
  echo "main ya tiene WIZARD_STEPS"
else
  echo "main NO tiene WIZARD_STEPS (staging en main = UI vieja)"
fi

echo ""
echo "=== Archivos locales sin commitear (checkout) ==="
git status --short resources/js/Pages/LaboratoryCheckout.jsx resources/js/Layouts/CheckoutLayout.jsx resources/js/Components/Checkout/ 2>/dev/null || true

echo ""
echo "=== Build de producción (local) ==="
if [ -f public/build/manifest.json ]; then
  echo "manifest.json existe ($(wc -c < public/build/manifest.json) bytes)"
  if grep -r "floatingWizardFooter" public/build/assets/*.js 2>/dev/null | head -1; then
    echo "OK: bundle compilado contiene floatingWizardFooter"
  else
    echo "AVISO: corre 'npm run build' y vuelve a buscar en public/build/assets/"
  fi
else
  echo "Sin public/build/manifest.json — en local usas probablemente 'npm run dev' (Vite)"
fi
