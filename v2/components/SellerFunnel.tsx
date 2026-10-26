import React from 'react';
import { SELLER_FEATURES } from '../constants';
import type { SellerFeature } from '../types';

const FeatureCard: React.FC<{ feature: SellerFeature }> = ({ feature }) => (
    <div className="bg-white p-6 rounded-lg border border-gray-200 text-center">
        <div className="flex-shrink-0 bg-blue-100 h-16 w-16 rounded-full flex items-center justify-center mx-auto">
            <i className={`fas ${feature.icon} text-blue-600 text-3xl`}></i>
        </div>
        <h3 className="mt-5 text-xl font-bold text-gray-900">{feature.title}</h3>
        <p className="mt-2 text-gray-600">{feature.description}</p>
    </div>
);


const SellerFunnel: React.FC = () => {
  return (
    <section className="py-20 sm:py-24 bg-gray-50 border-t border-gray-200">
      <div className="container mx-auto px-4 sm:px-6 lg:px-8">
        <div className="text-center max-w-4xl mx-auto">
            <h2 className="text-3xl sm:text-4xl font-bold tracking-tight text-gray-900">Partner with the Best. Grow Your Brand.</h2>
            <p className="mt-4 text-lg text-gray-600">
             Join our curated ecosystem of premium wellness brands and connect with a dedicated community of high-performance individuals. We provide the tools, you bring the passion.
            </p>
        </div>
        <div className="mt-16 grid grid-cols-1 md:grid-cols-3 gap-8">
          {SELLER_FEATURES.map((feature) => (
            <FeatureCard key={feature.title} feature={feature} />
          ))}
        </div>
        <div className="mt-16 text-center">
            <a href="#" className="inline-block bg-blue-600 text-white hover:bg-blue-700 font-bold px-10 py-4 rounded-md transition-all duration-300 transform hover:scale-105 text-lg shadow-lg">
                Become a Partner
            </a>
            <p className="mt-4 text-sm text-gray-500">One unified system for sellers, brands, and affiliates.</p>
        </div>
      </div>
    </section>
  );
};

export default SellerFunnel;
