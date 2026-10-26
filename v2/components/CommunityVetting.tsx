import React from 'react';

const FeatureCard: React.FC<{ icon: string; title: string; children: React.ReactNode }> = ({ icon, title, children }) => (
    <div className="bg-gray-50 p-6 rounded-lg border border-gray-200">
        <div className="flex items-center">
            <div className="flex-shrink-0 bg-blue-100 h-12 w-12 rounded-md flex items-center justify-center">
                <i className={`fas ${icon} text-blue-600 text-2xl`}></i>
            </div>
            <h3 className="ml-4 text-xl font-bold text-gray-900">{title}</h3>
        </div>
        <p className="mt-4 text-gray-600">{children}</p>
    </div>
);

const CommunityVetting: React.FC = () => {
  return (
    <section id="vetting" className="py-20 sm:py-24 bg-white">
      <div className="container mx-auto px-4 sm:px-6 lg:px-8">
        <div className="text-center">
          <h2 className="text-3xl sm:text-4xl font-bold tracking-tight text-gray-900">Community Vetted. Premium Only.</h2>
          <p className="mt-4 max-w-3xl mx-auto text-lg text-gray-600">
            Our "compliance moat" isn't just a backend feature—it's our promise to you. We enforce strict standards to ensure only the highest quality brands and products make it onto our platform.
          </p>
        </div>
        <div className="mt-12 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
          <FeatureCard icon="fa-shield-halved" title="Rigorous Vetting">
            Every vendor undergoes a multi-step verification process to ensure compliance, quality, and alignment with our community's values.
          </FeatureCard>
          <FeatureCard icon="fa-award" title="Premium Standards">
            We enforce strict guidelines for product imagery, descriptions, and customer service to maintain a premium, trustworthy shopping experience.
          </FeatureCard>
          <FeatureCard icon="fa-users-gear" title="Community Driven">
            Our standards are continuously refined based on feedback from our most trusted members—the Wellness Seekers and Platform Professionals.
          </FeatureCard>
        </div>
        <div className="mt-16 text-center">
            <a href="#" className="inline-block bg-transparent border-2 border-blue-600 text-blue-600 hover:bg-blue-600 hover:text-white font-bold px-8 py-3 rounded-md transition-all duration-300 transform hover:scale-105 text-lg">
                Learn About Our Standards
            </a>
            <p className="mt-4 text-sm text-gray-500">We are highly selective to maintain platform integrity.</p>
        </div>
      </div>
    </section>
  );
};

export default CommunityVetting;