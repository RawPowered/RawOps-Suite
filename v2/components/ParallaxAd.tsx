import React from 'react';
import { PRODUCTS } from '../constants';

const ParallaxAd: React.FC = () => {
  const featuredProduct = PRODUCTS.find(p => p.id === 5); // Featuring ISO-Peak Whey Protein

  if (!featuredProduct) return null;

  return (
    <section 
      className="relative min-h-screen flex items-center justify-center text-white parallax-bg bg-center bg-cover bg-no-repeat"
      style={{ backgroundImage: "url('https://images.unsplash.com/photo-1543362906-acfc16c67564?q=80&w=1965&auto=format&fit=crop')" }}
    >
      <div className="absolute inset-0 bg-black/60 z-10"></div>
      
      <div className="container mx-auto px-4 sm:px-6 lg:px-8 relative z-20 text-center flex flex-col items-center">
        <span className="text-sm font-bold uppercase tracking-widest text-blue-400">Feature Spotlight</span>
        <h2 className="text-4xl sm:text-5xl md:text-6xl font-extrabold tracking-tight mt-2">
          {featuredProduct.name}
        </h2>
        <p className="mt-4 max-w-2xl mx-auto text-lg md:text-xl text-gray-200">
          {featuredProduct.description}
        </p>

        <div className="mt-10 bg-white/10 backdrop-blur-md rounded-xl p-8 border border-white/20 flex flex-col md:flex-row items-center gap-6 md:gap-10">
          <p className="text-5xl font-bold tracking-tight">${featuredProduct.price.toFixed(2)}</p>
          <button className="bg-blue-600 text-white hover:bg-blue-700 w-16 h-16 rounded-full flex items-center justify-center transition-all duration-300 transform hover:scale-110 shadow-lg" aria-label="Add to cart">
            <i className="fas fa-plus text-2xl"></i>
          </button>
           <a href="#" className="inline-block bg-white text-gray-900 hover:bg-gray-200 font-bold px-8 py-4 rounded-md transition-all duration-300 transform hover:scale-105 text-lg">
                Shop Now
            </a>
        </div>
      </div>
    </section>
  );
};

export default ParallaxAd;
