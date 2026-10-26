import React from 'react';

const Hero: React.FC = () => {
  return (
    <section className="relative h-[70vh] min-h-[600px] flex items-center justify-center text-center overflow-hidden bg-gray-100">
       <div 
        className="absolute inset-0 bg-cover bg-center"
        style={{ backgroundImage: "url('https://images.unsplash.com/photo-1521737604893-d14cc237f11d?q=80&w=2084&auto=format&fit=crop')" }}
      ></div>
      <div className="absolute inset-0 bg-white/60 backdrop-blur-sm z-10"></div>
      
      <div className="container mx-auto px-4 sm:px-6 lg:px-8 relative z-20 flex flex-col items-center">
        <h1 className="text-4xl sm:text-5xl md:text-6xl font-extrabold tracking-tight text-gray-900">
          Find Your Edge.
        </h1>
        <p className="mt-4 max-w-2xl mx-auto text-lg md:text-xl text-gray-700">
          Use our AI-powered search to find the perfect products for your wellness goals.
        </p>
        
        <div className="mt-8 w-full max-w-2xl mx-auto">
          <div className="relative">
            <input 
              type="search" 
              name="ai-search"
              placeholder="Search for 'creatine' or ask 'what helps with focus?'"
              className="w-full pl-6 pr-16 py-4 bg-white/80 border border-gray-300 rounded-full text-lg text-gray-900 placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all duration-300 shadow-lg"
            />
            <button 
              type="submit" 
              className="absolute inset-y-0 right-0 flex items-center justify-center w-16 h-full text-white bg-blue-600 hover:bg-blue-700 rounded-full transition-colors duration-300"
              aria-label="Search"
            >
              <i className="fas fa-search text-xl"></i>
            </button>
          </div>
        </div>

        <div className="mt-8 inline-flex items-center justify-center px-6 py-3 text-base font-medium rounded-md text-gray-800">
          <div className="relative inline-flex">
            <span className="flex items-center">
              <span className="w-3 h-3 bg-green-500 rounded-full mr-2 animate-pulse-custom"></span>
              Trusted by 100K+ Producters
            </span>
          </div>
        </div>
      </div>
    </section>
  );
};

export default Hero;