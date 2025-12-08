// Welcome.jsx - Rediseñado completamente
import { Link } from "@inertiajs/react";
import useTrackingEvents from "@/Hooks/useTrackingEvents";
import { Testimonials } from "@/Pages/Welcome/testimonials";
import clsx from "clsx";
import FamedicLayout from "@/Layouts/FamedicLayout";

export default function Home() {
  useTrackingEvents();

  return (
    <FamedicLayout title="¡Bienvenido a Famedic!">
      {/* Hero con dashboard moderno */}
      <div className="space-y-16 lg:space-y-24">
        <DashboardHero />
        
        {/* Panel de servicios principales */}
        <ServicesDashboard />
        
        {/* Membresía y planes */}
        <MembershipPanel />
        
        {/* Accesos rápidos */}
        <QuickAccessPanel />
        
        {/* Promociones destacadas */}
        <FeaturedPromotions />
        
        {/* Testimonios */}
        <div id="preguntas" className="pt-8">
          <Testimonials />
        </div>
      </div>
    </FamedicLayout>
  );
}

function DashboardHero() {
  return (
    <div className="relative overflow-hidden bg-gradient-to-br from-gray-900 via-famedic-darker to-famedic-blue">
      {/* Efectos de fondo */}
      <div className="absolute inset-0">
        <div className="absolute inset-0 bg-[radial-gradient(circle_at_30%_20%,rgba(120,119,198,0.3),transparent_50%)]" />
        <div className="absolute inset-0 bg-[radial-gradient(circle_at_70%_80%,rgba(74,222,128,0.2),transparent_50%)]" />
      </div>
      
      <div className="relative mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 pt-20 pb-24 lg:pt-24 lg:pb-32">
        <div className="lg:grid lg:grid-cols-2 lg:gap-16 items-center">
          <div className="text-center lg:text-left">
            {/* Badge de plataforma */}
            <div className="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-white/10 backdrop-blur-sm mb-6 border border-white/20">
              <span className="text-xl">🚀</span>
              <span className="text-sm font-medium text-white">Plataforma de Salud Digital</span>
            </div>
            
            <h1 className="font-poppins text-4xl font-bold tracking-tight text-white sm:text-5xl lg:text-6xl">
              Tu salud,
              <span className="block text-famedic-lime mt-2">
                gestionada inteligentemente
              </span>
            </h1>
            
            <p className="mt-6 text-lg text-gray-200 max-w-2xl">
              Suscripción médica 24/7, farmacia digital, estudios de laboratorio con descuentos exclusivos. 
              Todo integrado en una plataforma moderna y segura.
            </p>
            
            {/* Estadísticas en tiempo real */}
            <div className="mt-8 flex items-center gap-6">
              <div className="flex items-center gap-2">
                <div className="w-2 h-2 rounded-full bg-green-500 animate-pulse" />
                <span className="text-sm text-gray-300">24/7 activo</span>
              </div>
              <div className="flex items-center gap-2">
                <div className="w-2 h-2 rounded-full bg-blue-500 animate-pulse" />
                <span className="text-sm text-gray-300">+500 consultas hoy</span>
              </div>
            </div>
            
            {/* CTA Principal */}
            <div className="mt-10 flex flex-col sm:flex-row gap-4 justify-center lg:justify-start">
              <Link
                href={route("register")}
                className="group inline-flex items-center justify-center px-8 py-4 text-base font-semibold rounded-xl bg from-famedic-lime text-famedic-darker bg-famedic-lime hover:shadow-2xl hover:scale-105 transition-all duration-300 transform shadow-lg"
              >
                <span>Iniciar suscripción gratuita</span>
                <span className="ml-2 group-hover:translate-x-1 transition-transform">→</span>
              </Link>
              <Link
                href="#servicios"
                className="inline-flex items-center justify-center px-8 py-4 text-base font-semibold rounded-xl border-2 border-white/30 text-white hover:bg-white/10 transition-all backdrop-blur-sm"
              >
                Explorar plataforma
              </Link>
            </div>
          </div>
          
          {/* Dashboard Preview */}
          <div className="mt-12 lg:mt-0 relative">
            <div className="relative mx-auto max-w-lg">
              {/* Efecto de brillo */}
              <div className="absolute -inset-4 bg-gradient-to-r from-famedic-lime/20 via-famedic-blue/20 to-famedic-light/20 rounded-3xl blur-2xl opacity-50" />
              
              {/* Dashboard principal */}
              <div className="relative bg-gray-900/80 backdrop-blur-sm rounded-2xl shadow-2xl overflow-hidden border border-gray-700/50">
                {/* Header del dashboard */}
                <div className="bg-gray-800/50 px-6 py-4 border-b border-gray-700/50">
                  <div className="flex items-center justify-between">
                    <div className="flex items-center gap-3">
                      <div className="w-3 h-3 rounded-full bg-red-500" />
                      <div className="w-3 h-3 rounded-full bg-yellow-500" />
                      <div className="w-3 h-3 rounded-full bg-green-500" />
                    </div>
                    <div className="text-sm font-medium text-gray-300">famedic.com.mx</div>
                    <div className="flex items-center gap-2">
                      <div className="w-8 h-8 rounded-full bg-gradient-to-r from-famedic-lime to-green-500 flex items-center justify-center">
                        <span className="text-sm font-bold">F</span>
                      </div>
                    </div>
                  </div>
                </div>
                
                {/* Contenido del dashboard */}
                <div className="p-6">
                  {/* Barra de navegación */}
                  <div className="flex items-center justify-between mb-8">
                    <div className="flex items-center gap-1">
                      <button className="px-4 py-2 text-sm font-medium text-gray-300 hover:text-white transition-colors">
                        Inicio
                      </button>
                      <button className="px-4 py-2 text-sm font-medium text-gray-300 hover:text-white transition-colors">
                        Consultas
                      </button>
                      <button className="px-4 py-2 text-sm font-medium text-gray-300 hover:text-white transition-colors">
                        Farmacia
                      </button>
                      <button className="px-4 py-2 text-sm font-medium text-gray-300 hover:text-white transition-colors">
                        Laboratorios
                      </button>
                    </div>
                    <div className="flex items-center gap-2">
                      <button className="px-3 py-1 text-xs rounded-lg bg-famedic-blue/20 text-famedic-blue">
                        Premium
                      </button>
                    </div>
                  </div>
                  
                  {/* Stats cards */}
                  <div className="grid grid-cols-3 gap-4 mb-6">
                    <div className="bg-gray-800/50 rounded-xl p-4">
                      <div className="text-2xl font-bold text-white">24/7</div>
                      <div className="text-xs text-gray-400">Consultas</div>
                    </div>
                    <div className="bg-gray-800/50 rounded-xl p-4">
                      <div className="text-2xl font-bold text-white">50%</div>
                      <div className="text-xs text-gray-400">Descuento</div>
                    </div>
                    <div className="bg-gray-800/50 rounded-xl p-4">
                      <div className="text-2xl font-bold text-white">✓</div>
                      <div className="text-xs text-gray-400">Envío gratis</div>
                    </div>
                  </div>
                  
                  {/* Acciones rápidas */}
                  <div className="grid grid-cols-2 gap-4">
                    <button className="bg-gradient-to-r from-famedic-blue to-cyan-600 rounded-xl p-4 text-white text-sm font-medium hover:opacity-90 transition-opacity">
                      <div className="text-lg mb-1">👨‍⚕️</div>
                      Consulta ahora
                    </button>
                    <button className=" to-emerald-600 bg-famedic-lime rounded-xl p-4 text-dark text-sm font-medium hover:opacity-90 transition-opacity">
                      <div className="text-lg mb-1">💊</div>
                      Pedir medicamentos
                    </button>
                  </div>
                  
                  {/* Progreso de membresía */}
                  <div className="mt-6 pt-6 border-t border-gray-700/50">
                    <div className="flex items-center justify-between mb-2">
                      <span className="text-sm text-gray-300">Membresía Premium</span>
                      <span className="text-xs text-famedic-lime">1 mes gratis</span>
                    </div>
                    <div className="w-full bg-gray-700/50 rounded-full h-2">
                      <div className="bg-gradient-to-r from-famedic-lime to-green-500 bg-famedic-lime h-2 rounded-full w-1/4"></div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

function ServicesDashboard() {
  const services = [
    {
      icon: "👨‍⚕️",
      title: "Médico Familiar 24/7",
      description: "Suscripción mensual con consultas ilimitadas por chat, voz o video. Primer mes gratis.",
      features: ["Consulta inmediata", "Receta digital", "Seguimiento continuo", "Sin citas previas"],
      color: "from-lime-600 to-lime-500",
      action: "Suscribirse ahora",
      href: "/suscripcion",
      badge: "Popular"
    },
    {
      icon: "💊",
      title: "Farmacia Digital",
      description: "Más de 10,000 medicamentos con entrega express en 2-4 horas. Envío gratis desde $500.",
      features: ["Envío express", "Precios competitivos", "Receta integrada", "Stock garantizado"],
      color: "from-lime-600 to-lime-500",
      action: "Explorar catálogo",
      href: "/farmacia",
      badge: "Express"
    },
    {
      icon: "🧪",
      title: "Laboratorios Aliados",
      description: "Estudios clínicos con hasta 50% de descuento. Agenda en línea y resultados digitales.",
      features: ["50% descuento", "Agenda en línea", "Resultados digitales", "+100 estudios"],
      color: "from-lime-600 to-lime-500",
      action: "Ver estudios",
      href: "/laboratorios",
      badge: "50% OFF"
    }
  ];

  return (
    <div id="servicios" className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-16">
      {/* Header con navegación tipo dashboard */}
      <div className="flex items-center justify-between mb-12">
        <div>
          <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-gray-100 text-gray-800 text-sm font-medium mb-4">
            <span>📊</span>
            <span>Servicios activos</span>
          </div>
          <h2 className="font-poppins text-3xl font-bold tracking-tight text-gray-900 text-white sm:text-4xl">
            Todo lo que necesitas para tu salud
          </h2>
          <p className="mt-4 text-lg text-gray-600 max-w-3xl">
            Accede a nuestra suite completa de servicios de salud digital diseñada para tu bienestar.
          </p>
        </div>
        <Link
          href="/dashboard"
          className="hidden lg:flex items-center gap-2 px-6 py-3 rounded-xl border-2 border-gray-200 text-gray-700 text-white hover:border-famedic-light hover:text-famedic-light transition-colors"
        >
          <span>Ir a mi perfil</span>
          <span>→</span>
        </Link>
      </div>

      {/* Grid de servicios */}
      <div className="grid gap-8 lg:grid-cols-3">
        {services.map((service, index) => (
          <div 
            key={index}
            className="group relative bg-white rounded-2xl p-6 shadow-lg hover:shadow-2xl transition-all duration-300 border border-gray-100 hover:border-gray-200"
          >
            {service.badge && (
              <div className="absolute -top-3 right-6">
                <span className={`inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-gradient-to-r ${service.color} text-white`}>
                  {service.badge}
                </span>
              </div>
            )}
            
            <div className={`inline-flex p-3 rounded-xl bg-gradient-to-br ${service.color} text-white mb-6`}>
              <span className="text-2xl">{service.icon}</span>
            </div>
            
            <h3 className="text-xl font-bold text-gray-900 mb-3">
              {service.title}
            </h3>
            
            <p className="text-gray-600 mb-6">
              {service.description}
            </p>
            
            {/* Lista de características */}
            <ul className="space-y-2 mb-8">
              {service.features.map((feature, idx) => (
                <li key={idx} className="flex items-center gap-2 text-sm">
                  <div className="w-1.5 h-1.5 rounded-full bg-gradient-to-r from-green-500 to-emerald-500" />
                  <span className="text-gray-700">{feature}</span>
                </li>
              ))}
            </ul>
            
            <div className="mt-auto">
              <Link
                href={service.href}
                className="inline-flex items-center justify-between w-full px-4 py-3 rounded-xl bg-gray-50 hover:bg-gray-100 text-gray-800 font-medium transition-colors group"
              >
                <span>{service.action}</span>
                <span className="opacity-0 group-hover:opacity-100 group-hover:translate-x-1 transition-all">→</span>
              </Link>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

function MembershipPanel() {
  const plans = [
    {
      name: "Básico",
      price: "$0",
      period: "por siempre",
      description: "Perfecto para empezar",
      features: [
        "1 consulta gratuita/mes",
        "10% descuento en farmacia",
        "15% descuento en laboratorios",
        "Historial básico"
      ],
      cta: "Empezar gratis",
      href: "/register",
      popular: false
    },
    {
      name: "Premium",
      price: "$299",
      period: "por mes",
      description: "El más popular",
      features: [
        "Consultas médicas ilimitadas 24/7",
        "20% descuento en farmacia",
        "50% descuento en laboratorios",
        "Historial completo + recetas digitales",
        "Envío gratis en farmacia",
        "Prioridad en atención"
      ],
      cta: "Probar 7 días gratis",
      href: "/suscripcion",
      popular: true
    },
    {
      name: "Familia",
      price: "$499",
      period: "por mes",
      description: "Para toda la familia",
      features: [
        "Hasta 5 miembros incluidos",
        "Consultas ilimitadas para todos",
        "30% descuento en farmacia",
        "50% descuento en laboratorios",
        "Historial familiar completo",
        "Envío gratis en farmacia",
        "Atención prioritaria"
      ],
      cta: "Proteger mi familia",
      href: "/suscripcion/familia",
      popular: false
    }
  ];
}

function QuickAccessPanel() {
  const quickLinks = [
    {
      icon: "🩺",
      title: "Consultar ahora",
      description: "Habla con un médico en menos de 30 minutos",
      href: "/medical-attention",
      color: "bg-blue-400 text-blue-700",
      badge: "24/7"
    },
    {
      icon: "💊",
      title: "Pedir medicamentos",
      description: "Entrega express en 2-4 días",
      href: "/online-pharmacy",
      color: "bg-blue-400 text-blue-700",
      badge: "Express"
    },
    {
      icon: "📋",
      title: "Agendar estudio",
      description: "Laboratorios con hasta 50% descuento",
      href: "/laboratory-brand-selection",
      color: "bg-blue-400 text-blue-700",
      badge: "50% OFF"
    },
    {
      icon: "📊",
      title: "Ver resultados",
      description: "Accede a tus resultados de laboratorio",
      href: "/laboratory-purchases",
      color: "bg-blue-400 text-blue-700",
      badge: "Digital"
    },
    {
      icon: "👨‍👩‍👧‍👦",
      title: "Agregar familiar",
      description: "Gestiona la salud de tu familia",
      href: "/medical-attention",
      color: "bg-blue-400 text-blue-700",
      badge: "Familiar"
    },
    {
      icon: "🧾",
      title: "Facturación",
      description: "Descarga tus facturas y recibos",
      href: "/tax-profiles",
      color: "bg-blue-400 text-blue-700",
      badge: "CFDI"
    }
  ];

  return (
    <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-16">
      <div className="flex items-center justify-between mb-12">
        <div>
          <h2 className="font-poppins text-3xl font-bold tracking-tight text-gray-900 text-white sm:text-4xl">
            Acceso rápido
          </h2>
          <p className="mt-4 text-lg text-gray-600 max-w-3xl">
            Acciones más frecuentes para una experiencia fluida
          </p>
        </div>
        <div className="hidden lg:block">
          <div className="flex items-center gap-2 text-sm text-gray-500">
            <div className="w-2 h-2 rounded-full bg-green-500 animate-pulse" />
            <span>Plataforma activa</span>
          </div>
        </div>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {quickLinks.map((link, index) => (
          <Link
            key={index}
            href={link.href}
            className="group relative bg-white rounded-xl p-6 shadow-sm hover:shadow-md border border-gray-100 hover:border-gray-200 transition-all hover:-translate-y-1"
          >
            <div className="flex items-start justify-between">
              <div className={`inline-flex p-3 rounded-lg ${link.color.split(' ')[0]} mb-4`}>
                <span className="text-xl">{link.icon}</span>
              </div>
              {link.badge && (
                <span className="text-xs font-medium px-2 py-1 rounded-full bg-gray-100 text-gray-700">
                  {link.badge}
                </span>
              )}
            </div>
            
            <h4 className="font-semibold text-gray-900 mb-2">
              {link.title}
            </h4>
            <p className="text-sm text-gray-600 mb-4">
              {link.description}
            </p>
            
            <div className="flex items-center text-sm font-medium text-gray-700 group-hover:text-famedic-light transition-colors">
              <span>Acceder</span>
              <span className="ml-2 opacity-0 group-hover:opacity-100 group-hover:translate-x-1 transition-all">→</span>
            </div>
          </Link>
        ))}
      </div>
    </div>
  );
}

function FeaturedPromotions() {
  const promotions = [
    {
      title: "Chequeo General Esencial",
      originalPrice: "$468",
      price: "$234",
      discount: "50%",
      includes: [
        "Biometría hemática",
        "Química sanguínea",
        "Examen general de orina"        
      ],
      image: "https://images.pexels.com/photos/8460348/pexels-photo-8460348.jpeg",
      href: "/laboratory-brand-selection"
    },
    {
      title: "Paquetes de Medicamentos",
      tag: "Ahorra hasta 40%",
      description: "Combos de medicamentos esenciales con entrega gratis",
      image: "https://images.pexels.com/photos/3683037/pexels-photo-3683037.jpeg",
      href: "/online-pharmacy"
    },
    {
      title: "Primera consulta gratis",
      tag: "Nuevos miembros",
      description: "Consulta con especialista sin costo en tu primer mes",
      image: "https://images.pexels.com/photos/5998445/pexels-photo-5998445.jpeg",
      href: "/medical-attention"
    }
  ];

  return (
    <div className="bg-gradient-to-br from-famedic-light/5 to-famedic-blue/5 py-16">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div className="text-center mb-12">
          <div className="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-gradient-to-r from-famedic-lime to-lime-400 text-dark font-medium mb-4">
            <span>🔥</span>
            <span>Promociones activas</span>
          </div>
          <h2 className="font-poppins text-3xl font-bold tracking-tight text-gray-900 text-white sm:text-4xl">
            Ofertas exclusivas para ti
          </h2>
        </div>

        <div className="grid gap-8 lg:grid-cols-3">
          {/* Promoción principal */}
          <div className="lg:col-span-2">
            <div className="relative bg-white rounded-2xl shadow-xl overflow-hidden border border-gray-100">
              <div className="lg:grid lg:grid-cols-2">
                <div className="p-8 lg:p-10">
                  <div className="inline-flex items-center px-3 py-1 rounded-full bg-red-100 text-red-700 text-sm font-medium mb-4">
                    <span>-50%</span>
                  </div>
                  <h3 className="text-2xl font-bold text-gray-900 mb-4">
                    {promotions[0].title}
                  </h3>
                  
                  <div className="flex items-baseline gap-3 mb-6">
                    <span className="text-3xl font-bold text-gray-900">{promotions[0].price}</span>
                    <span className="text-lg text-gray-500 line-through">{promotions[0].originalPrice}</span>
                    <span className="text-sm font-semibold text-red-600">Ahorra {promotions[0].discount}</span>
                  </div>
                  
                  <ul className="space-y-3 mb-8">
                    {promotions[0].includes.map((item, idx) => (
                      <li key={idx} className="flex items-center gap-3">
                        <div className="w-5 h-5 rounded-full bg-green-100 flex items-center justify-center flex-shrink-0">
                          <div className="w-2 h-2 rounded-full bg-green-600" />
                        </div>
                        <span className="text-gray-700">{item}</span>
                      </li>
                    ))}
                  </ul>
                  
                  <Link
                    href={promotions[0].href}
                    className="inline-flex items-center justify-center w-full lg:w-auto px-8 py-3 rounded-xl bg-gradient-to-r from-famedic-lime to-lime-400 text-famedic-darker font-semibold hover:shadow-lg transition-shadow"
                  >
                    Agendar ahora
                    <span className="ml-2">📅</span>
                  </Link>
                </div>
                
                <div className="relative h-64 lg:h-auto">
                  <img
                    src={promotions[0].image}
                    alt={promotions[0].title}
                    className="absolute inset-0 w-full h-full object-cover"
                  />
                  <div className="absolute inset-0 bg-gradient-to-r from-white via-white/70 to-transparent lg:bg-gradient-to-r lg:from-transparent lg:via-transparent lg:to-black/20" />
                </div>
              </div>
            </div>
          </div>

          {/* Promociones secundarias */}
          <div className="space-y-6">
            {promotions.slice(1).map((promo, index) => (
              <Link
                key={index}
                href={promo.href}
                className="group relative bg-white rounded-2xl shadow-lg overflow-hidden border border-gray-100 hover:shadow-xl transition-shadow block"
              >
                <div className="relative h-48">
                  <img
                    src={promo.image}
                    alt={promo.title}
                    className="absolute inset-0 w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
                  />
                  <div className="absolute inset-0 bg-gradient-to-t from-black/60 to-transparent" />
                  {promo.tag && (
                    <div className="absolute top-4 left-4">
                      <span className="inline-flex items-center px-3 py-1 rounded-full bg-white text-gray-900 text-sm font-medium">
                        {promo.tag}
                      </span>
                    </div>
                  )}
                  <div className="absolute bottom-4 left-4 right-4">
                    <h4 className="text-lg font-bold text-white mb-1">
                      {promo.title}
                    </h4>
                    <p className="text-sm text-gray-200">
                      {promo.description}
                    </p>
                  </div>
                </div>
              </Link>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}

function Title({ children, level = 1, className }) {
  let Element = `h${level}`;

  return (
    <Element
      className={clsx(
        "font-poppins text-4xl font-bold tracking-tight text-gray-900 sm:text-5xl lg:text-6xl",
        className
      )}
    >
      {children}
    </Element>
  );
}