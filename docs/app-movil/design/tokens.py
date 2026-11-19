#!/usr/bin/env python3
"""Genera design/tokens.dart desde resources/css/app.css de PriceTrack (oklch → sRGB).

Uso: python3 design/tokens.py /ruta/a/PriceTrack/resources/css/app.css > lib/theme/tokens.dart
"""
import math
import re
import sys

css = open(sys.argv[1]).read()


def oklch(L, C, h):
    a, b = C * math.cos(math.radians(h)), C * math.sin(math.radians(h))
    l_, m_, s_ = L + 0.3963377774 * a + 0.2158037573 * b, L - 0.1055613458 * a - 0.0638541728 * b, L - 0.0894841775 * a - 1.2914855480 * b
    l, m, s = l_**3, m_**3, s_**3
    rgb = (4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s,
           -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s,
           -0.0041960863 * l - 0.7034186147 * m + 1.7076147010 * s)

    def gamma(x):
        x = max(0, min(1, x))
        x = 12.92 * x if x <= 0.0031308 else 1.055 * x ** (1 / 2.4) - 0.055
        return round(max(0, min(1, x)) * 255)

    return '0xFF%02X%02X%02X' % tuple(gamma(x) for x in rgb)


def block(start, end):
    i = css.index(start)
    return css[i:css.index(end, i)]


def variables(text):
    return {k: oklch(float(L), float(C), float(h)) for k, L, C, h in re.findall(r'--([\w-]+): oklch\(([\d.]+) ([\d.]+) ([\d.]+)\);', text)}


def camel(key):
    parts = key.replace('color-', '').split('-')
    return parts[0] + ''.join(p.capitalize() for p in parts[1:])


light = variables(block('@theme static', '}\n\n:root'))
dark = variables(block("[data-theme='dark'] {", '/* Color de cada sección'))
keys = [k for k in light if k.startswith('color-')]

# En Dart `super` es palabra reservada: la sección Súper se llama `supermercado`
sections = {'inicio': ('inicio', 'Inicio'), 'super': ('supermercado', 'Súper'), 'cocina': ('cocina', 'Cocina'),
            'hogar': ('hogar', 'Hogar'), 'finanzas': ('finanzas', 'Finanzas')}

out = ["// GENERADO desde resources/css/app.css (los colores oklch del tema «papel y jade» pasados a sRGB).",
       "// Para regenerarlo cuando cambie el tema de la web: docs/DISENO.md («Colores»).",
       "import 'package:material_ui/material_ui.dart';", "",
       "/// Paleta del tema: mismas familias que la web (slate = papel y tinta, emerald = jade,",
       "/// red = chile, amber = cempasúchil). En oscuro la escala se invierte, igual que en la web.",
       "class Palette {", "  const Palette({"]
out += [f"    required this.{camel(k)}," for k in keys] + ["  });", ""]
out += [f"  final Color {camel(k)};" for k in keys]
for name, values in (('light', light), ('dark', dark)):
    out += ["", f"  static const {name} = Palette("] + [f"    {camel(k)}: Color({values[k]})," for k in keys] + ["  );"]
out += ["}", "", "/// Color de cada sección: fuerte, fondo suave y tinta sobre el fondo suave.", "class SectionColors {",
        "  const SectionColors(this.sec, this.soft, this.ink);", "  final Color sec;", "  final Color soft;", "  final Color ink;", "}", "",
        "enum AppSection {"]
rows = []
for css_name, (dart_name, label) in sections.items():
    l = variables(re.search(r"\n(?:\[data-section='%s'\],\n)?\[data-nav='%s'\] \{(.*?)\}" % (css_name, css_name), css, re.S).group(1))
    d = variables(re.search(r"\n(?:\[data-theme='dark'\] \[data-section='%s'\],\n)?\[data-theme='dark'\] \[data-nav='%s'\] \{(.*?)\}" % (css_name, css_name), css, re.S).group(1))
    rows.append(f"  {dart_name}('{label}', SectionColors(Color({l['sec']}), Color({l['sec-soft']}), Color({l['sec-ink']})),\n"
                f"      SectionColors(Color({d['sec']}), Color({d['sec-soft']}), Color({d['sec-ink']})))")
out += [',\n'.join(rows) + ';', "", "  const AppSection(this.label, this.light, this.dark);", "  final String label;",
        "  final SectionColors light;", "  final SectionColors dark;", "",
        "  SectionColors of(Brightness b) => b == Brightness.dark ? dark : light;", "}"]
print('\n'.join(out))
