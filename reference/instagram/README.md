# MAVÉA Instagram Flyer Production Pack

## Status

This pack covers the 20 dresses found in `C:\ClaudeCode\mavea\dresses`. It is ready for generation once the required source library is available.

The project checkout does not currently contain `C:\ClaudeCode\mavea\dresses\source` or `C:\ClaudeCode\mavea\dresses\source\face`. No replacement face has been used. This preserves the required model identity and avoids creating detail crops from generated root images.

## Deliverable

Create one 4:5 portrait Instagram flyer for each dress. Each flyer contains one full-length lifestyle view, one garment-detail inset sourced from the relevant source asset, and restrained MAVÉA branding on a porcelain panel. The only product title is the main name.

## Production flow

1. Restore or provide `dresses\source\face` and one source garment image plus one detail image for each dress.
2. Generate one complete flyer per dress using the corresponding prompt in `PROMPTS.md`.
3. Check that the face matches the source, hair is fully covered, the garment is correct, the complete hem is visible, and the three required text strings are exact.
4. Reject flyers with misspelled text, altered garment details, studio backdrops, exposed hair, or a detail inset derived from a generated root image.
5. Save approved PNG files as `01-suhana.png` through `20-naila.png`.

## Cost and quality choice

Use one composed flyer generation per dress, with three direct references: the face source, the full garment source, and the detail source. This produces a complete post in one generation instead of paying for separate lifestyle, close-up, and layout passes. Generate two calibration posts first, then continue with the remaining 18 only after the text, face, and garment checks pass.

## Copy rules

All product copy is a complete, factual sentence. The only non-sentence strings are the MAVÉA wordmark, the main-name title, and `www.mavea.lk`. No price, sizing, availability, claims, urgency, or unverified material details appear in the flyers.
