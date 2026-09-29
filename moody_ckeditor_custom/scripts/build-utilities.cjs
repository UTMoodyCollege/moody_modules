// One catalog generates the public CSS, guide, and AI class allowlist.
// Run with Node; no dependencies. --check verifies checked-in outputs.
const fs = require('node:fs');
const path = require('node:path');
const root = path.join(__dirname, '..');
const breakpoints = { base: 0, sm: 576, md: 768, lg: 992, xl: 1200 };
const rules = {};
const add = (name, declarations) => { rules[`ut-${name}`] = declarations; };
for (const value of ['block', 'inline-block', 'flex', 'inline-flex', 'grid', 'none']) add(`display-${value}`, `display:${value}`);
for (const value of ['row', 'column']) add(`flex-${value}`, `flex-direction:${value}`);
for (const value of ['wrap', 'nowrap']) add(`flex-${value}`, `flex-wrap:${value}`);
for (const [name, value] of Object.entries({ start: 'flex-start', center: 'center', end: 'flex-end', stretch: 'stretch' })) add(`items-${name}`, `align-items:${value}`);
for (const [name, value] of Object.entries({ start: 'flex-start', center: 'center', end: 'flex-end', between: 'space-between', around: 'space-around' })) add(`justify-${name}`, `justify-content:${value}`);
for (const value of ['start', 'center', 'end', 'stretch', 'auto']) add(`self-${value}`, `align-self:${value}`);
for (const value of ['left', 'center', 'right', 'start', 'end']) add(`text-${value}`, `text-align:${value}`);
for (let count = 1; count <= 6; count++) add(`cols-${count}`, `grid-template-columns:repeat(${count},minmax(0,1fr))`);
for (const [name, value] of Object.entries({ halves: '1fr 1fr', thirds: '1fr 1fr 1fr', 'one-two': '1fr 2fr', 'two-one': '2fr 1fr', 'one-three': '1fr 3fr', 'three-one': '3fr 1fr' })) add(`split-${name}`, `grid-template-columns:${value.split(' ').map(v => `minmax(0,${v})`).join(' ')}`);
for (const [name, value] of Object.entries({ auto: 'auto', full: '100%', half: '50%', third: '33.333333%', 'two-thirds': '66.666667%', quarter: '25%', 'three-quarters': '75%' })) add(`w-${name}`, `width:${value};max-width:100%`);
add('min-w-0', 'min-width:0');
add('h-full', 'height:100%');
add('h-auto', 'height:auto');
for (const [name, value] of Object.entries({ narrow: '45ch', standard: '65ch', wide: '80ch', none: 'none' })) add(`measure-${name}`, `max-width:${value}`);
for (const value of ['static', 'relative', 'absolute', 'sticky']) add(`position-${value}`, `position:${value}`);
for (const edge of ['top', 'right', 'bottom', 'left', 'inset']) {
  for (const [name, value] of Object.entries({ 0: '0', half: '50%', auto: 'auto' })) add(`${edge}-${name}`, `${edge}:${value}`);
}
add('translate-center', 'transform:translate(-50%,-50%)');
add('translate-none', 'transform:none');
for (const value of ['cover', 'contain', 'fill', 'none']) add(`object-${value}`, `object-fit:${value}`);
for (const x of ['left', 'center', 'right']) for (const y of ['top', 'center', 'bottom']) add(`object-${x}-${y}`, `object-position:${x} ${y}`);
for (const [name, value] of Object.entries({ square: '1 / 1', landscape: '4 / 3', wide: '16 / 9', portrait: '3 / 4', auto: 'auto' })) add(`aspect-${name}`, `aspect-ratio:${value}`);
for (const value of ['visible', 'hidden', 'auto']) add(`overflow-${value}`, `overflow:${value}`);
for (const value of [0, 1, 2]) add(`layer-${value}`, `z-index:${value}`);
for (const [axis, sides] of Object.entries({ '': [''], t: ['-top'], r: ['-right'], b: ['-bottom'], l: ['-left'], x: ['-left', '-right'], y: ['-top', '-bottom'] })) {
  for (const [prefix, property] of [['p', 'padding'], ['m', 'margin']]) {
    for (let size = 0; size <= 32; size++) add(`${prefix}${axis}-${size}`, sides.map(side => `${property}${side}:${size / 4}rem`).join(';'));
    if (prefix === 'm') add(`${prefix}${axis}-auto`, sides.map(side => `${property}${side}:auto`).join(';'));
  }
}
for (const size of [0, 1, 2, 3, 4, 6, 8, 12, 16]) add(`gap-${size}`, `gap:${size / 4}rem`);
for (const [size, value] of Object.entries({ xs: .75, sm: .875, base: 1, lg: 1.125, xl: 1.25, '2xl': 1.5, '3xl': 1.875, '4xl': 2.25, '5xl': 3 })) add(`text-${size}`, `font-size:${value}rem`);
for (const [name, value] of Object.entries({ light: 300, normal: 400, medium: 500, semibold: 600, bold: 700, black: 900 })) add(`font-weight-${name}`, `font-weight:${value}`);
add('font-sans', 'font-family:"LibreFrank",Arial,Helvetica,sans-serif');
add('font-serif', 'font-family:"CharisSil",Georgia,serif');
add('border-radius-none', 'border-radius:0');
const palettes = { white: ['#fff', '#333f48'], paper: ['#f2f1ed', '#333f48'], charcoal: ['#333f48', '#fff'], orange: ['#a04400', '#fff'] };
for (const [name, [background, color]] of Object.entries(palettes)) add(`surface-${name}`, `background-color:${background};color:${color}`);
const examples = [
  ['Responsive card grid', '<div class="ut-display-grid ut-cols-1 md:ut-cols-2 lg:ut-cols-3 ut-gap-6"><article class="ut-surface-paper ut-p-6"><h3>First card</h3><p>Card copy.</p></article><article class="ut-surface-paper ut-p-6"><h3>Second card</h3><p>Card copy.</p></article><article class="ut-surface-paper ut-p-6"><h3>Third card</h3><p>Card copy.</p></article></div>'],
  ['Stack on mobile, split on desktop', '<div class="ut-display-grid ut-cols-1 lg:ut-split-halves ut-gap-6 ut-items-center"><div><h3>Heading</h3><p>Introductory copy.</p></div><div class="ut-text-start lg:ut-text-end"><a class="ut-cta-link" href="/">Explore our site</a></div></div>'],
  ['Reading width and responsive spacing', '<div class="ut-measure-standard ut-mx-auto ut-p-4 md:ut-p-8"><h3>Readable text</h3><p>A centered, bounded reading width with comfortable padding.</p></div>'],
  ['Hero-like content panel', '<section class="ut-surface-charcoal ut-p-6 lg:ut-p-12"><div class="ut-measure-standard ut-ml-auto"><h3 class="ut-text-2xl lg:ut-text-4xl">A clear introduction</h3><p>Use Hero Builder for interactive media, overlays and managed buttons.</p></div></section>'],
];
const summary = `Use only documented Moody utilities. Base classes apply at every viewport; sm: starts at 576px, md: 768px, lg: 992px, xl: 1200px, mobile-first and cascading upward. Use a base value plus breakpoint overrides. Do not confuse viewport widths with Hero/Card Builder container queries (tablet 600px, desktop 900px).\nLayout: ut-display-{block,inline-block,flex,inline-flex,grid,none}, ut-cols-{1..6}, ut-split-{halves,thirds,one-two,two-one,one-three,three-one}, ut-flex-{row,column,wrap,nowrap}, ut-items-{start,center,end,stretch}, ut-justify-{start,center,end,between,around}, ut-self-{start,center,end,stretch,auto}, ut-text-{left,center,right,start,end}.\nSizing: ut-w-{auto,full,half,third,two-thirds,quarter,three-quarters}, ut-min-w-0, ut-h-{auto,full}, ut-measure-{narrow,standard,wide,none}. Spacing: ut-{p,pt,pr,pb,pl,px,py,m,mt,mr,mb,ml,mx,my}-{0..32}, each step .25rem; margins also accept auto. Gap: ut-gap-{0,1,2,3,4,6,8,12,16}.\nMedia: ut-object-{cover,contain,fill,none}, ut-object-{left,center,right}-{top,center,bottom}, ut-aspect-{square,landscape,wide,portrait,auto}. Use on the actual image, not a drupal-media wrapper; use the managed Media settings if the image is nested.\nType: ut-text-{xs,sm,base,lg,xl,2xl,3xl,4xl,5xl}, ut-font-weight-{light,normal,medium,semibold,bold,black}, ut-font-{sans,serif}. Surfaces: ut-surface-{white,paper,charcoal,orange}; approved foreground/background pairs. Square corners only. Do not generate retired teal/bright palette classes or rounded-corner options.\nPosition: ut-position-{static,relative,absolute,sticky}, ut-{top,right,bottom,left,inset}-{0,half,auto}, ut-translate-{center,none}, ut-layer-{0,1,2}. Prefer grid/flex for text; absolute text can overlap at zoom. Do not hide essential content or change visual reading order. Never use positioning to obscure navigation. Use Hero/Card Builder for managed responsive media and editable structured content; their internal mhb-/mcb- classes are not standalone utilities. All listed utilities support the viewport prefixes. No inline CSS needed.`;
const escape = text => text.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;');
const classes = [];
let css = '/* Generated by scripts/build-utilities.cjs. Edit the catalog, not this file. */\n';
for (const [prefix, width] of Object.entries(breakpoints)) {
  const body = Object.entries(rules).map(([name, declarations]) => {
    const className = prefix === 'base' ? name : `${prefix}:${name}`;
    classes.push(className);
    return `.${className.replace(':', '\\:')}{${declarations.split(';').map(d => `${d}!important`).join(';')}}`;
  }).join('\n');
  css += width ? `@media(min-width:${width}px){\n${body}\n}\n` : `${body}\n`;
}
css += '[class*="ut-surface-"] :where(h1,h2,h3,h4,h5,h6,p,a){color:inherit}\n';
css += '#moody-responsive-utilities{max-width:72rem;margin:2rem auto;padding:clamp(1rem,3vw,2rem);overflow-wrap:anywhere}#moody-responsive-utilities pre{padding:1rem;background:#f2f1ed;font-size:.875rem}#moody-responsive-utilities details{margin:1rem 0}#moody-responsive-utilities summary{cursor:pointer;font-weight:700}#moody-responsive-utilities th,#moody-responsive-utilities td{padding:.5rem;text-align:left;border-bottom:1px solid #d6d2c4}\n';
const guide = `<section id="moody-responsive-utilities"><h2>Moody responsive utilities</h2><p>This reference is generated from the same catalog as the CSS and AI allowlist. It supplements existing theme utilities; it does not require a Hero or Card Builder block on the page.</p>${summary.split('\n').map(p => `<p>${escape(p)}</p>`).join('')}<h3>Recipes</h3>${examples.map(([title, html]) => `<h4>${title}</h4>${html}<pre><code>${escape(html)}</code></pre>`).join('')}<h3>Exact class reference</h3><p>Every base class below supports sm:, md:, lg:, and xl:. Later breakpoints override earlier values regardless of class order in HTML. Use one value per property per breakpoint. Padding is inside an element; margin is outside; gap belongs on its flex/grid parent. Percentage heights require a parent with a defined height. Hidden content is also hidden from assistive technology.</p><table><caption>Utility declarations</caption><thead><tr><th scope="col">Base class</th><th scope="col">CSS</th></tr></thead><tbody>${Object.entries(rules).map(([name, declarations]) => `<tr><th scope="row"><code>${name}</code></th><td><code>${escape(declarations)}</code></td></tr>`).join('')}</tbody></table></section>`;
const guideOutput = guide.replaceAll('<pre>', '<pre class="ut-overflow-auto">').replace('<h3>Exact class reference</h3>', '<details><summary>Exact class reference</summary>').replace(/<\/section>$/, '</details></section>');
for (const [file, content] of Object.entries({ 'css/moody-responsive-utilities.css': css, 'utility-guide.html': guideOutput, 'utility-context.txt': summary + '\n', 'utility-classes.json': JSON.stringify(classes, null, 2) + '\n' })) {
  const target = path.join(root, file);
  if (process.argv.includes('--check')) {
    if (!fs.existsSync(target) || fs.readFileSync(target, 'utf8') !== content) throw new Error(`Stale generated artifact: ${file}`);
  } else fs.writeFileSync(target, content);
}
