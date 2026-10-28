/* Tourfecto — 3D hero (Three.js). Falls back to the CSS globe if WebGL/Three is unavailable. */
(function () {
  var stage = document.getElementById('globeStage');
  if (!stage || typeof THREE === 'undefined') return;
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var renderer;
  try { renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true }); } catch (e) { return; }
  renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
  var cv = renderer.domElement;
  cv.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;z-index:0;';
  stage.insertBefore(cv, stage.firstChild);
  stage.classList.add('has-3d');

  var scene = new THREE.Scene();
  var camera = new THREE.PerspectiveCamera(42, 1, 0.1, 100);
  camera.position.set(0, 0, 7);
  var R = 2, GOLD = 0xD4AF37, TEAL = 0x4ECDC4;

  function ll(lat, lon, r) {
    var p = (90 - lat) * Math.PI / 180, t = (lon + 180) * Math.PI / 180;
    return new THREE.Vector3(-r * Math.sin(p) * Math.cos(t), r * Math.cos(p), r * Math.sin(p) * Math.sin(t));
  }

  var globe = new THREE.Group();
  scene.add(globe);

  // solid core + soft atmosphere
  globe.add(new THREE.Mesh(new THREE.SphereGeometry(R * 0.985, 48, 48),
    new THREE.MeshBasicMaterial({ color: 0x0a1428 })));
  var atm = new THREE.Mesh(new THREE.SphereGeometry(R * 1.14, 48, 48),
    new THREE.MeshBasicMaterial({ color: GOLD, transparent: true, opacity: 0.07, side: THREE.BackSide }));
  globe.add(atm);

  // dotted surface (Fibonacci sphere)
  var N = 2600, pos = new Float32Array(N * 3), ga = Math.PI * (3 - Math.sqrt(5));
  for (var i = 0; i < N; i++) {
    var y = 1 - (i / (N - 1)) * 2, rad = Math.sqrt(1 - y * y), th = ga * i;
    pos[i * 3] = Math.cos(th) * rad * R; pos[i * 3 + 1] = y * R; pos[i * 3 + 2] = Math.sin(th) * rad * R;
  }
  var g = new THREE.BufferGeometry();
  g.setAttribute('position', new THREE.BufferAttribute(pos, 3));
  globe.add(new THREE.Points(g, new THREE.PointsMaterial({ color: GOLD, size: 0.028, transparent: true, opacity: 0.85 })));

  // cities + flight arcs
  var cities = [[30, 31], [25, 55], [48.8, 2.3], [51.5, -0.1], [40.7, -74], [35.7, 139.7], [-8.4, 115], [41.9, 12.5], [21.4, 39.8]];
  var pairs = [[0, 1], [0, 2], [1, 5], [3, 4], [0, 6], [7, 4], [8, 3], [2, 5]];
  var movers = [];
  cities.forEach(function (c) {
    var m = new THREE.Mesh(new THREE.SphereGeometry(0.045, 12, 12), new THREE.MeshBasicMaterial({ color: TEAL }));
    m.position.copy(ll(c[0], c[1], R * 1.005)); globe.add(m);
  });
  pairs.forEach(function (p, k) {
    var a = ll(cities[p[0]][0], cities[p[0]][1], R), b = ll(cities[p[1]][0], cities[p[1]][1], R);
    var mid = a.clone().add(b).multiplyScalar(0.5).normalize().multiplyScalar(R * (1.25 + a.distanceTo(b) * 0.06));
    var curve = new THREE.QuadraticBezierCurve3(a, mid, b);
    globe.add(new THREE.Line(new THREE.BufferGeometry().setFromPoints(curve.getPoints(60)),
      new THREE.LineBasicMaterial({ color: GOLD, transparent: true, opacity: 0.55 })));
    var d = new THREE.Mesh(new THREE.SphereGeometry(0.05, 12, 12), new THREE.MeshBasicMaterial({ color: 0xffffff }));
    globe.add(d); movers.push({ d: d, c: curve, t: k / pairs.length, s: 0.0025 + (k % 3) * 0.0008 });
  });

  // orbit rings
  var rings = [];
  [[2.7, 1.2, 0.2], [3.05, 0.4, 0.9], [3.4, 1.9, -0.5]].forEach(function (r, i) {
    var t = new THREE.Mesh(new THREE.TorusGeometry(r[0], 0.006, 8, 220),
      new THREE.MeshBasicMaterial({ color: i === 1 ? TEAL : GOLD, transparent: true, opacity: 0.35 }));
    t.rotation.set(r[1], r[2], 0); scene.add(t); rings.push(t);
  });

  // star field
  var SN = 700, sp = new Float32Array(SN * 3);
  for (var s = 0; s < SN; s++) {
    var v = new THREE.Vector3(Math.random() - .5, Math.random() - .5, Math.random() - .5).normalize().multiplyScalar(9 + Math.random() * 8);
    sp.set([v.x, v.y, v.z], s * 3);
  }
  var sg = new THREE.BufferGeometry(); sg.setAttribute('position', new THREE.BufferAttribute(sp, 3));
  var stars = new THREE.Points(sg, new THREE.PointsMaterial({ color: 0xffffff, size: 0.035, transparent: true, opacity: 0.55 }));
  scene.add(stars);

  function resize() {
    var w = stage.clientWidth, h = stage.clientHeight;
    renderer.setSize(w, h, false); camera.aspect = w / h;
    camera.position.z = w / h < 0.9 ? 8.5 : 7; camera.updateProjectionMatrix();
  }
  resize(); window.addEventListener('resize', resize);

  var mx = 0, my = 0, tx = 0, ty = 0, visible = true;
  stage.addEventListener('mousemove', function (e) {
    var r = stage.getBoundingClientRect();
    tx = ((e.clientX - r.left) / r.width - .5) * 0.9; ty = ((e.clientY - r.top) / r.height - .5) * 0.5;
  });
  stage.addEventListener('mouseleave', function () { tx = 0; ty = 0; });
  new IntersectionObserver(function (en) { visible = en[0].isIntersecting; }).observe(stage);

  globe.rotation.z = 0.25;
  function loop() {
    requestAnimationFrame(loop);
    if (!visible) return;
    mx += (tx - mx) * 0.05; my += (ty - my) * 0.05;
    if (!reduce) {
      globe.rotation.y += 0.0032;
      stars.rotation.y += 0.0002;
      rings.forEach(function (r, i) { r.rotation.z += 0.002 * (i + 1) * (i % 2 ? -1 : 1); });
      movers.forEach(function (m) { m.t = (m.t + m.s) % 1; m.d.position.copy(m.c.getPoint(m.t)); });
    }
    scene.rotation.y = mx; scene.rotation.x = my;
    renderer.render(scene, camera);
  }
  loop();
})();

/* 3D scroll depth: dashboard preview flattens as it reaches the viewport centre + extra tilt cards */
(function () {
  var dash = document.querySelector('.dash');
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (dash && !reduce) {
    var upd = function () {
      var r = dash.getBoundingClientRect(), vh = window.innerHeight;
      var p = Math.max(0, Math.min(1, (r.top + r.height / 2 - vh / 2) / vh));
      dash.style.setProperty('--rx', (p * 22).toFixed(2) + 'deg');
    };
    window.addEventListener('scroll', upd, { passive: true }); upd();
  }
  document.querySelectorAll('.example-card,.step-card,.stat-block').forEach(function (c) {
    if (reduce) return;
    c.addEventListener('mousemove', function (e) {
      var r = c.getBoundingClientRect();
      var x = (e.clientX - r.left) / r.width - .5, y = (e.clientY - r.top) / r.height - .5;
      c.style.transform = 'perspective(800px) rotateY(' + (x * 10) + 'deg) rotateX(' + (-y * 10) + 'deg) translateZ(6px)';
    });
    c.addEventListener('mouseleave', function () { c.style.transform = ''; });
  });
})();
