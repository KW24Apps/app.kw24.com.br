/* Hover do donut por vendedor (go.Barpolar — 2 anéis).
 *
 * Ao apontar um vendedor (na LEGENDA ou numa FATIA do donut), a barra interna
 * dele E suas barras externas (interno/indicado) saltam pra fora RADIALMENTE —
 * a barra é empurrada ao longo do seu próprio theta aumentando base/r (pull
 * exatamente radial, sem o efeito "torto" dos pies aninhados). As demais
 * esmaecem. Mouse-out restaura.
 *
 * Mapeamento (garantido por _vend_donut_rows no app.py):
 *   vendedor i  ↔  barra interna i (trace 0)  ↔  barras externas 2i / 2i+1 (trace 1)
 *
 * trace 0 = anel INTERNO (N barras) · trace 1 = anel EXTERNO (2N barras)
 * trace 2 = textos de % (Scatterpolar, hoverinfo skip — não dispara pull).
 *
 * Além do hover, este arquivo desenha os NOMES dos vendedores FORA do donut
 * (linhas-guia + rótulos em SVG puro) no plotly_afterplot — ver drawLabels(). */
(function () {
    var DR = 0.06;   // deslocamento radial do pull (fração do raio)

    function fade(c) {
        if (!c) return c;
        if (c[0] === "#") {
            var h = c.slice(1);
            return "rgba(" + parseInt(h.slice(0, 2), 16) + "," +
                parseInt(h.slice(2, 4), 16) + "," + parseInt(h.slice(4, 6), 16) + ",0.22)";
        }
        var m = c.match(/rgba?\(([^)]+)\)/);
        if (m) {
            var p = m[1].split(",");
            return "rgba(" + p[0].trim() + "," + p[1].trim() + "," + p[2].trim() + ",0.22)";
        }
        return c;
    }

    function donutGd() {
        var d = document.querySelector(".ct-donut");
        return d ? d.querySelector(".js-plotly-plot") : null;
    }

    function ready(gd) {
        return gd && gd.data && gd.data[0] && gd.data[1] &&
            gd.data[0].marker && gd.data[1].marker &&
            Array.isArray(gd.data[0].r) && Array.isArray(gd.data[1].r);
    }

    function snap(gd) {
        return {
            b0: gd.data[0].base.slice(), r0: gd.data[0].r.slice(), c0: gd.data[0].marker.color.slice(),
            b1: gd.data[1].base.slice(), r1: gd.data[1].r.slice(), c1: gd.data[1].marker.color.slice(),
        };
    }

    function applyPull(gd, idx) {
        if (!ready(gd)) return;
        if (!gd._ctPr) gd._ctPr = snap(gd);
        var pr = gd._ctPr;
        var n = pr.b0.length;        // N (interno)
        var m = pr.b1.length;        // 2N (externo)
        if (idx < 0 || idx >= n) return;

        var b0 = [], r0 = [], c0 = [];
        for (var i = 0; i < n; i++) {
            var on = i === idx;
            b0.push(pr.b0[i] + (on ? DR : 0));
            r0.push(pr.r0[i]);   // comprimento fica 0.20 — barra só desloca p/ fora
            c0.push(on ? pr.c0[i] : fade(pr.c0[i]));
        }
        var b1 = [], r1 = [], c1 = [];
        for (var j = 0; j < m; j++) {
            var on2 = Math.floor(j / 2) === idx;
            b1.push(pr.b1[j] + (on2 ? DR : 0));
            r1.push(pr.r1[j]);   // idem anel externo — só desloca, não alarga
            c1.push(on2 ? pr.c1[j] : fade(pr.c1[j]));
        }
        window.Plotly.restyle(gd, {
            base: [b0, b1], r: [r0, r1], "marker.color": [c0, c1],
        }, [0, 1]);
    }

    function restore(gd) {
        if (gd && gd._ctPr) {
            var pr = gd._ctPr;
            window.Plotly.restyle(gd, {
                base: [pr.b0, pr.b1], r: [pr.r0, pr.r1], "marker.color": [pr.c0, pr.c1],
            }, [0, 1]);
            gd._ctPr = null;
        }
    }

    // ── Hover na LEGENDA (índice = posição do item entre os itens de vendedor) ──
    document.addEventListener("mouseover", function (e) {
        if (!e.target.closest) return;
        var leg = e.target.closest(".ct-donut-legend");
        if (!leg) return;
        var item = e.target.closest(".ct-leg-item");
        if (!item) return;                       // rodapé (.ct-leg-foot) é ignorado
        var items = leg.querySelectorAll(".ct-leg-item");
        var idx = Array.prototype.indexOf.call(items, item);
        if (idx >= 0) applyPull(donutGd(), idx);
    });

    document.addEventListener("mouseout", function (e) {
        if (!e.target.closest) return;
        var leg = e.target.closest(".ct-donut-legend");
        if (!leg) return;
        var to = e.relatedTarget;
        if (to && leg.contains(to)) return;      // ainda dentro da legenda → ignora
        restore(donutGd());
    });

    // ── Nomes dos vendedores FORA do donut (linhas-guia + rótulos em SVG puro) ──
    // Sem textposition/leader-line nativo do Plotly: lemos a geometria do polar do
    // SVG renderizado e desenhamos tudo à mão. Dados via gd._fullLayout.meta
    // (nome, ângulo da fatia, cor) — sem aproximação paper↔polar.
    var SVGNS = "http://www.w3.org/2000/svg";

    function metaOf(gd) {
        var fl = gd._fullLayout || gd.layout || {};
        return fl.meta || (gd.layout && gd.layout.meta) || null;
    }

    // Fallback: maior <circle>/<rect>/<path> dentro de um root (em área de tela).
    function largestShape(root) {
        if (!root) return null;
        var els = root.querySelectorAll("circle, rect, path");
        var best = null, bestA = -1;
        for (var i = 0; i < els.length; i++) {
            var r;
            try { r = els[i].getBoundingClientRect(); } catch (e) { continue; }
            var a = r.width * r.height;
            if (a > bestA) { bestA = a; best = els[i]; }
        }
        return best;
    }

    function drawLabels(gd) {
        var meta = metaOf(gd);
        if (!meta || !meta.vendedores || !meta.vendedores.length) return;

        var svg = gd.querySelector("svg");
        if (!svg) return;

        // 1. Background do polar + geometria EM PIXELS DE TELA (via
        //    getBoundingClientRect), descontando o offset do <svg> → coords no
        //    espaço de usuário do SVG (1 unidade = 1px). Robusto a transforms.
        var bgEl = gd.querySelector(".polar > .bg, .polarlayer .bg, .polar rect.bg, .polar path.bg");
        if (!bgEl) bgEl = largestShape(gd.querySelector(".polarlayer"));
        if (!bgEl) return;
        var rect = bgEl.getBoundingClientRect();
        var svgRect = svg.getBoundingClientRect();
        if (!rect.width || !rect.height) return;
        var cx = rect.left - svgRect.left + rect.width / 2;
        var cy = rect.top - svgRect.top + rect.height / 2;
        var R = rect.width / 2;

        // 2. Remove o grupo de rótulos anterior (evita duplicar a cada afterplot).
        var prev = gd.querySelector(".ct-donut-labels");
        if (prev && prev.parentNode) prev.parentNode.removeChild(prev);

        // 3. Novo <g> no mesmo <svg>.
        var g = document.createElementNS(SVGNS, "g");
        g.setAttribute("class", "ct-donut-labels");
        svg.appendChild(g);

        var rEdge = R * (meta.r_outer_top || 0.96);   // borda do anel externo
        var CLEAR = R + 8;                              // rótulo nunca dentro do donut

        // CHANGE 4A: limites do CARD (em coords do SVG) → rótulos não escapam para
        // o card vizinho (ex.: "Equipe" à esquerda).
        var cardLeft = -Infinity, cardRight = Infinity;
        var card = (typeof gd.closest === "function")
            ? gd.closest(".ct-donut-card, .table-panel, [class*='card']") : null;
        if (card) {
            var cr = card.getBoundingClientRect();
            cardLeft = cr.left - svgRect.left + 8;
            cardRight = cr.right - svgRect.left - 8;
        }

        // 4. Geometria de cada rótulo. y1 == y2 (2º segmento horizontal).
        var items = meta.vendedores.map(function (v) {
            var theta = v.theta;                          // graus, horário a partir do topo
            var rad = (theta - 90) * Math.PI / 180;
            var ca = Math.cos(rad), sa = Math.sin(rad);
            var x0 = cx + rEdge * ca, y0 = cy + rEdge * sa;     // início na borda externa
            var right = (Math.cos(rad) >= 0);
            // CHANGE 4B: lado direito mais curto (24px) p/ não chegar perto da legenda.
            var len = rEdge + R * 0.10;
            var x1 = cx + len * ca, y1 = cy + len * sa;          // cotovelo
            // Garante que o rótulo NUNCA invada o donut: estende até clarear R+8.
            while (Math.sqrt((x1 - cx) * (x1 - cx) + (y1 - cy) * (y1 - cy)) < CLEAR) {
                len += 4; x1 = cx + len * ca; y1 = cy + len * sa;
            }
            var x2 = right ? x1 + 14 : x1 - 14;
            return { name: v.name, x0: x0, y0: y0, x1: x1, y1: y1, x2: x2, y: y1,
                     anchor: right ? "start" : "end", right: right };
        });

        // 5. Anti-sobreposição: por LADO, ordena por y e empurra p/ baixo os que
        //    estiverem a < 14px do anterior (offset = diferença + 4px). (Dois lados
        //    têm colunas x distintas → declutter vertical independente por coluna.)
        ["right", "left"].forEach(function (side) {
            var col = items.filter(function (it) { return side === "right" ? it.right : !it.right; })
                           .sort(function (a, b) { return a.y - b.y; });
            for (var i = 1; i < col.length; i++) {
                var dy = col[i].y - col[i - 1].y;
                if (dy < 14) {
                    var shift = (14 - dy) + 4;
                    col[i].y += shift;
                    col[i].y1 += shift;   // cotovelo acompanha (mantém 2º segmento horizontal)
                }
            }
        });

        // 6. Desenha linhas + rótulos (com clamp do x2 aos limites do card; o
        //    cotovelo x1 acompanha p/ manter o 2º segmento horizontal consistente).
        items.forEach(function (it) {
            var x2 = it.x2, x1 = it.x1;
            if (x2 < cardLeft) { x2 = cardLeft; x1 = it.right ? x2 - 14 : x2 + 14; }
            else if (x2 > cardRight) { x2 = cardRight; x1 = it.right ? x2 - 14 : x2 + 14; }
            g.appendChild(mkLine(it.x0, it.y0, x1, it.y1));   // radial (angulado)
            g.appendChild(mkLine(x1, it.y1, x2, it.y));        // horizontal até o rótulo
            var t = document.createElementNS(SVGNS, "text");
            t.setAttribute("x", x2);
            t.setAttribute("y", it.y);
            t.setAttribute("dy", "0.35em");
            t.setAttribute("text-anchor", it.anchor);
            t.setAttribute("font-family", "Inter, sans-serif");  // = .ct-leg-name
            t.setAttribute("font-size", "12px");
            t.setAttribute("font-weight", "600");
            t.setAttribute("fill", "#1f2937");
            t.textContent = it.name;
            g.appendChild(t);
        });
    }

    function mkLine(x1, y1, x2, y2) {
        var ln = document.createElementNS(SVGNS, "line");
        ln.setAttribute("x1", x1); ln.setAttribute("y1", y1);
        ln.setAttribute("x2", x2); ln.setAttribute("y2", y2);
        ln.setAttribute("stroke", "#94a3b8");
        ln.setAttribute("stroke-width", "1");
        ln.setAttribute("fill", "none");
        return ln;
    }

    // ── Hover nas FATIAS do donut + rótulos externos (eventos do Plotly) ──────
    function bind() {
        var gd = donutGd();
        if (gd && !gd._ctBound && typeof gd.on === "function") {
            gd._ctBound = true;
            gd.on("plotly_hover", function (d) {
                var p = d && d.points && d.points[0];
                if (!p) return;
                var idx = -1;
                if (p.curveNumber === 0) idx = p.pointNumber;
                else if (p.curveNumber === 1) idx = Math.floor(p.pointNumber / 2);
                if (idx >= 0) applyPull(gd, idx);
            });
            gd.on("plotly_unhover", function () { restore(gd); });
            // Redesenha os rótulos a cada render (1ª carga + re-render por filtro/data).
            gd.on("plotly_afterplot", function () { drawLabels(gd); });
            drawLabels(gd);   // o afterplot inicial pode ter ocorrido antes do bind
        }
    }
    // O gráfico é (re)criado de forma assíncrona pelo Dash; tentamos ligar até achar.
    setInterval(bind, 700);
    document.addEventListener("DOMContentLoaded", bind);
})();
